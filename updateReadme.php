#!/usr/bin/env php
<?php
/**
 * GitHub Bio Updater
 * Automatically fetches GitHub contributions and updates bio with current PR statuses.
 */

// Configuration
const GITHUB_USERNAME = 'ayyoub-afwallah';
const BIO_FILE = 'README.md';
const SCRIPT_FILE = __FILE__;
const API_BASE = 'https://api.github.com';

// Get GitHub token from environment or set it here
$githubToken = getenv('GITHUB_TOKEN') ?: null;

// Repositories to track (in display order)
const REPOS = [
        'symfony/symfony' => [
                'title' => '🏗️ Symfony Core',
                'order' => 1
        ],
        'symfony/symfony-docs' => [
                'title' => '📚 Documentation',
                'order' => 2
        ],
        'symfony-tools/carsonbot' => [
                'title' => '🤖 CarsonBot',
                'order' => 3
        ],
        'symfony-tools/fabbot' => [
                'title' => '🤖 Fabbot',
                'order' => 4
        ],
        'symfony/maker-bundle' => [
                'title' => '🛠️ Maker Bundle',
                'order' => 5
        ],
];

// Status badge configurations
const STATUS_BADGES = [
        'merged' => '![](https://img.shields.io/badge/Merged-purple?style=flat-square)',
        'open' => '![](https://img.shields.io/badge/Open-green?style=flat-square)',
        'closed' => '![](https://img.shields.io/badge/Closed-red?style=flat-square)',
        'draft' => '![](https://img.shields.io/badge/Draft-gray?style=flat-square)',
];

// Status order for sorting (lower number = first)
const STATUS_ORDER = [
        'merged' => 1,
        'open' => 2,
        'draft' => 3,
        'closed' => 4
];

class GitHubAPI
{
    private string $username;
    private ?string $token;
    private array $headers;
    private int $rateLimitRemaining = 0;

    public function __construct(string $username, ?string $token = null)
    {
        $this->username = $username;
        $this->token = $token;

        $this->headers = [
                'User-Agent: PHP-GitHub-Bio-Updater',
                'Accept: application/vnd.github.v3+json',
        ];

        if ($this->token) {
            $this->headers[] = "Authorization: Bearer {$this->token}";
        }
    }

    private function request(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $this->headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            echo "❌ cURL Error: " . curl_error($ch) . "\n";
            curl_close($ch);
            return null;
        }

        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Extract rate limit
        if (preg_match('/x-ratelimit-remaining: (\d+)/i', $header, $matches)) {
            $this->rateLimitRemaining = (int)$matches[1];
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            echo "⚠️  API returned status code: $httpCode for URL: $url\n";
            if ($httpCode === 403) {
                echo "   Rate limit exceeded. Consider adding a GitHub token.\n";
            }
            return null;
        }

        return json_decode($body, true);
    }

    public function searchPRs(string $query, int $perPage = 100): array
    {
        $url = API_BASE . '/search/issues?' . http_build_query([
                        'q' => $query,
                        'sort' => 'created',
                        'order' => 'desc',
                        'per_page' => $perPage,
                ]);

        $result = $this->request($url);
        return $result['items'] ?? [];
    }

    public function getPRDetails(string $repo, int $prNumber): ?array
    {
        $url = API_BASE . "/repos/$repo/pulls/$prNumber";
        return $this->request($url);
    }

    public function getUserPRs(array $repos): array
    {
        echo "🔍 Fetching PRs from GitHub API...\n";
        echo "📂 Tracking repositories:\n";
        foreach (array_keys($repos) as $repo) {
            echo "   • $repo\n";
        }
        echo "\n";

        // Build search query for all repos
        $repoQueries = array_map(fn($repo) => "repo:$repo", array_keys($repos));
        $query = 'is:pr author:' . $this->username . ' ' . implode(' ', $repoQueries);

        $allPRs = $this->searchPRs($query);
        echo "📊 Found " . count($allPRs) . " total PRs\n\n";

        // Fetch detailed info for each PR to get merge status
        $detailedPRs = [];
        foreach ($allPRs as $index => $pr) {
            $repoName = $this->extractRepoName($pr['repository_url']);
            $progress = $index + 1;
            $total = count($allPRs);
            echo "  [{$progress}/{$total}] Fetching PR #{$pr['number']} from $repoName...";

            $details = $this->getPRDetails($repoName, $pr['number']);
            if ($details) {
                $status = $this->determineStatus($details);
                $details['html_url'] = $pr['html_url'];
                $details['repository'] = $repoName;
                $details['status'] = $status;
                $detailedPRs[] = $details;
                echo " ✓ ($status)\n";
            } else {
                echo " ✗\n";
            }

            // Small delay to avoid hitting rate limits
            if ($this->rateLimitRemaining > 0 && $this->rateLimitRemaining < 10) {
                echo "⚠️  Rate limit low ({$this->rateLimitRemaining} remaining), slowing down...\n";
                sleep(1);
            } else {
                usleep(100000); // 0.1 second
            }
        }

        echo "\n✅ Fetched details for " . count($detailedPRs) . " PRs\n";
        if ($this->rateLimitRemaining > 0) {
            echo "📊 API Rate Limit: {$this->rateLimitRemaining} requests remaining\n";
        }

        return $detailedPRs;
    }

    private function determineStatus(array $pr): string
    {
        if ($pr['merged_at'] ?? false) {
            return 'merged';
        }
        if ($pr['draft'] ?? false) {
            return 'draft';
        }
        if ($pr['state'] === 'open') {
            return 'open';
        }
        if ($pr['state'] === 'closed') {
            return 'closed';
        }
        return 'unknown';
    }

    private function extractRepoName(string $repoUrl): string
    {
        $parts = explode('/', $repoUrl);
        return $parts[count($parts) - 2] . '/' . $parts[count($parts) - 1];
    }

    public function getRateLimitRemaining(): int
    {
        return $this->rateLimitRemaining;
    }
}

class PRFormatter
{
    private array $repos;

    public function __construct(array $repos)
    {
        $this->repos = $repos;
    }

    public function extractComponent(string $title, string $repo): string
    {
        // Try to extract component from [Component] pattern
        if (preg_match('/\[([^\]]+)\]/', $title, $matches)) {
            return trim($matches[1]);
        }

        // Check common patterns in title
        $componentPatterns = [
                'FrameworkBundle', 'DependencyInjection', 'Console', 'HttpKernel',
                'Process', 'Security', 'Cache', 'Form', 'Validator', 'Routing',
                'Serializer', 'Translation', 'Workflow', 'Messenger', 'Mailer'
        ];

        foreach ($componentPatterns as $component) {
            if (stripos($title, $component) !== false) {
                return $component;
            }
        }

        // Check for maker-bundle patterns
        if (str_contains($repo, 'maker-bundle')) {
            if (preg_match('/make[r]?:(\w+)/i', $title, $matches)) {
                return 'maker:' . strtolower($matches[1]);
            }
            if (preg_match('/Make:(\w+)/i', $title, $matches)) {
                return 'Make:' . $matches[1];
            }
            return 'MakerBundle';
        }

        // Default based on repo
        if (str_contains($repo, 'symfony-docs')) {
            return 'Documentation';
        } elseif (str_contains($repo, 'carsonbot')) {
            return 'CarsonBot';
        } elseif (str_contains($repo, 'fabbot')) {
            return 'Fabbot';
        }

        return 'General';
    }

    public function formatPR(array $pr): string
    {
        //$component = $this->extractComponent($pr['title'], $pr['repository']);
        $title = $pr['title'];
        $number = $pr['number'];
        $url = $pr['html_url'];
        $status = $pr['status'];

        // Special handling for symfony-docs merged PRs
        if (str_contains($pr['repository'], 'symfony-docs') && $status === 'merged') {
            $badge = '![](https://img.shields.io/badge/Published-blue?style=flat-square)';
        } else {
            $badge = STATUS_BADGES[$status] ?? STATUS_BADGES['open'];
        }

        return "* [{$title} #{$number}]({$url}) {$badge}";
    }

    public function groupByRepo(array $prs): array
    {
        $grouped = [];

        foreach ($prs as $pr) {
            $repo = $pr['repository'];
            if (!isset($grouped[$repo])) {
                $grouped[$repo] = [];
            }
            $grouped[$repo][] = $pr;
        }

        return $grouped;
    }

    public function sortPRs(array $prs): array
    {
        usort($prs, function ($a, $b) {
            // First sort by status (merged, open, draft, closed)
            $statusA = STATUS_ORDER[$a['status']] ?? 999;
            $statusB = STATUS_ORDER[$b['status']] ?? 999;

            if ($statusA !== $statusB) {
                return $statusA <=> $statusB;
            }

            // Within same status, sort by creation date (newest first)
            return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        });

        return $prs;
    }

    public function generateMarkdown(array $prs, array $repos): string
    {
        $grouped = $this->groupByRepo($prs);

        $lines = [
                '## Hi There, catch up with what i have been doing lately !',
                '*A collection of my recent contributions to the Symfony ecosystem.*'
        ];

        // Process repos in defined order
        foreach ($repos as $repoName => $config) {
            if (!isset($grouped[$repoName]) || empty($grouped[$repoName])) {
                continue;
            }

            $lines[] = "### {$config['title']}";

            // Sort PRs by status and date
            $sortedPRs = $this->sortPRs($grouped[$repoName]);

            foreach ($sortedPRs as $pr) {
                if($pr['status'] != 'closed')
                    $lines[] = $this->formatPR($pr);
            }
        }

        $lines[] = '---';
        $lines[] = '💬 **[View my full PR history](https://github.com/pulls?q=is%3Apr+author%3A' . GITHUB_USERNAME . ')**';
        $lines[] = '';

        // Use explicit \n for line endings
        return implode("\n", $lines);
    }
}

class BioUpdater
{
    private string $filepath;

    public function __construct(string $filepath)
    {
        $this->filepath = $filepath;
    }

    public function update(string $newContent): bool
    {
        $startMarker = '## Hi There, catch up with what i have been doing lately !';
        $endMarker = '💬 **[View my full PR history]';

        if (!file_exists($this->filepath)) {
            echo "⚠️  Bio file not found: {$this->filepath}\n";
            echo "📝 Creating new file...\n";
            file_put_contents($this->filepath, $newContent);
            echo "\n✅ File created successfully\n";
            return true;
        }

        $content = file_get_contents($this->filepath);

        // Find the section to replace
        $startPos = strpos($content, $startMarker);
        if ($startPos === false) {
            echo "⚠️  Contributions section not found, appending to end of file...\n";
            file_put_contents($this->filepath, $content . "\n\n" . $newContent);
            echo "\n✅ Content appended successfully\n";
            return true;
        }

        // Find the end of the section
        $endPos = strpos($content, "\n\n", strpos($content, $endMarker, $startPos));
        if ($endPos === false) {
            $endPos = strlen($content);
        } else {
            $endPos += 2; // Include the newlines
        }

        // Replace the section
        $newFullContent = substr($content, 0, $startPos) . $newContent . substr($content, $endPos);

        // Check if content changed
        if ($newFullContent === $content) {
            echo "ℹ️  No changes detected, bio is already up to date\n";
            return false;
        }

        // Write the file
        $bytesWritten = file_put_contents($this->filepath, $newFullContent);

        if ($bytesWritten === false) {
            echo "❌ Failed to write to file\n";
            return false;
        }

        echo "✅ Successfully updated {$this->filepath} ({$bytesWritten} bytes written)\n";
        return true;
    }
}

// Main execution
function main()
{
    global $githubToken;

    echo "=" . str_repeat("=", 59) . "\n";
    echo "🤖 GitHub Bio Updater (PHP)\n";
    echo "=" . str_repeat("=", 59) . "\n";
    echo "👤 Username: " . GITHUB_USERNAME . "\n";
    echo "📁 Bio file: " . BIO_FILE . "\n";
    echo "🔑 API Token: " . ($githubToken ? '✓ Configured' : '✗ Not set (rate limited)') . "\n";
    echo "=" . str_repeat("=", 59) . "\n\n";

    // Initialize API client
    $api = new GitHubAPI(GITHUB_USERNAME, $githubToken);

    // Fetch all PRs
    $prs = $api->getUserPRs(REPOS);

    if (empty($prs)) {
        echo "\n❌ No PRs found or API error occurred\n";
        exit(1);
    }

    // Format and generate markdown
    echo "\n📝 Generating markdown...\n";
    $formatter = new PRFormatter(REPOS);
    $markdown = $formatter->generateMarkdown($prs, REPOS);

    // Validate the markdown
    echo "📊 Generated markdown stats:\n";
    echo "   - Total length: " . strlen($markdown) . " bytes\n";
    echo "   - Number of lines: " . substr_count($markdown, "\n") . "\n";
    echo "   - Badge count: " . substr_count($markdown, '![](https://img.shields.io/badge/') . "\n";

    // Update bio file
    echo "\n💾 Updating bio file...\n";
    $updater = new BioUpdater(BIO_FILE);
    $updated = $updater->update($markdown);

    if ($updated) {
        // Verify what was written
        $writtenContent = file_get_contents(BIO_FILE);
        $badgeCountInFile = substr_count($writtenContent, '![](https://img.shields.io/badge/');
        echo "✓ Verification: Found {$badgeCountInFile} badges in the written file\n";

        // Robustness Check: Ensure no redundant headers
        $startMarker = '## Hi There, catch up with what i have been doing lately !';
        $headerCount = substr_count($writtenContent, $startMarker);

        if ($headerCount !== 1) {
            echo "\n❌ Safety Check Failed: Found {$headerCount} occurrences of the header. Expected exactly 1.\n";
            echo "   This indicates potential duplication or corruption. Aborting push.\n";
            return;
        }

        echo "✓ Safety Check: Content structure looks correct (single header found).\n";

        // Push to GitHub
        echo "\n📤 Pushing to GitHub...\n";
        pushToGitHub();
    }

    // Show a preview of the first few lines
    echo "\n📋 Preview of generated content:\n";
    echo str_repeat("-", 60) . "\n";
    $lines = explode("\n", $markdown);
    $previewLines = array_slice($lines, 0, min(15, count($lines)));
    echo implode("\n", $previewLines);
    if (count($lines) > 15) {
        echo "\n... (and " . (count($lines) - 15) . " more lines)\n";
    }
    echo "\n" . str_repeat("-", 60) . "\n";

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "✨ Update complete!\n";
    echo str_repeat("=", 60) . "\n";
}

function pushToGitHub()
{
    // Check if we're in a git repository
    exec('git rev-parse --is-inside-work-tree 2>&1', $output, $returnCode);
    if ($returnCode !== 0) {
        echo "⚠️  Not a git repository. Skipping push.\n";
        return false;
    }

    // Check if there are changes
    exec('git status --porcelain ' . BIO_FILE, $statusOutput);
    if (empty($statusOutput)) {
        echo "ℹ️  No changes to commit.\n";
        return false;
    }

    echo "📝 Committing changes...\n";

    // Add the file
    exec('git add ' . BIO_FILE .' '.SCRIPT_FILE.' 2>&1', $addOutput, $addReturn);
    if ($addReturn !== 0) {
        echo "❌ Failed to add file: " . implode("\n", $addOutput) . "\n";
        return false;
    }

    // Commit
    $commitMessage = '🤖 Auto-update GitHub contributions [' . date('Y-m-d H:i:s') . ']';
    exec('git commit -m ' . escapeshellarg($commitMessage) . ' 2>&1', $commitOutput, $commitReturn);
    if ($commitReturn !== 0) {
        echo "❌ Failed to commit: " . implode("\n", $commitOutput) . "\n";
        return false;
    }
    echo "✓ Committed changes\n";

    // Push
    echo "📤 Pushing to remote...\n";
    exec('git push 2>&1', $pushOutput, $pushReturn);
    if ($pushReturn !== 0) {
        echo "❌ Failed to push: " . implode("\n", $pushOutput) . "\n";
        echo "💡 You may need to push manually with: git push\n";
        return false;
    }

    echo "✅ Successfully pushed to GitHub!\n";
    echo "🔗 View at: https://github.com/ayyoub-afwallah/ayyoub-afwallah\n";
    return true;
}

// Run the script
try {
    main();
} catch (Exception $e) {
    echo "\n❌ Fatal error: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
