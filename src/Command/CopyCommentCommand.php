<?php
/*
 * Copyright (c) 2025. IOWEB TECHNOLOGIES
 */

namespace App\Command;

use App\Service\GithubService;
use App\Service\TranslationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'copy:comment',
    description: 'Copy a GitHub issue comment to a new issue in another repo.'
)]
class CopyCommentCommand extends Command
{
    public function __construct(
        private GithubService $githubService,
        private TranslationService $translationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('comment-link', null, InputOption::VALUE_REQUIRED, 'GitHub comment link')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run mode');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commentLink = $input->getOption('comment-link');
        $dryRun = $input->getOption('dry-run');

        $targetOwner = $_ENV['GITHUB_TARGET_OWNER'] ?? null;
        $targetRepo = $_ENV['GITHUB_TARGET_REPO'] ?? null;
        $translateTo = $_ENV['GITHUB_DEFAULT_LANG'] ?? null;

        if (!$commentLink) {
            $output->writeln('<error>--comment-link is required.</error>');
            return Command::FAILURE;
        }
        if (!$targetOwner || !$targetRepo || !$translateTo) {
            $output->writeln('<error>Missing required environment variables: GITHUB_TARGET_OWNER, GITHUB_TARGET_REPO, GITHUB_DEFAULT_LANG. Check your .env.etennis file.</error>');
            return Command::FAILURE;
        }

        // Parse owner/repo/commentId from link
        $parsed = $this->parseCommentLink($commentLink);
        if (!$parsed) {
            $output->writeln('<error>Cannot parse owner/repo/commentId from --comment-link</error>');
            return Command::FAILURE;
        }

        [$sourceOwner, $sourceRepo, $commentId] = [$parsed['owner'], $parsed['repo'], $parsed['commentId']];

        // Fetch comment and source issue
        $fetched = $this->fetchCommentAndIssue($sourceOwner, $sourceRepo, $commentId);
        if (!$fetched['comment']) {
            $output->writeln('<error>Failed to fetch comment from GitHub.</error>');
            return Command::FAILURE;
        }

        $payload = $this->buildPayload($fetched['comment'], $fetched['issue'], $sourceOwner, $sourceRepo, $translateTo, $commentLink);

        if ($dryRun) {
            $output->writeln("[DRY RUN] Would create issue in $targetOwner/$targetRepo");
            $output->writeln("Title: {$payload['title']}\n\n{$payload['body']}");
            return Command::SUCCESS;
        }

        $created = $this->githubService->createIssue($targetOwner, $targetRepo, $payload);
        $output->writeln("✅ Created new issue: " . $created['html_url']);
        return Command::SUCCESS;
    }

    private function parseCommentLink(string $link): ?array
    {
        if (!preg_match('#github\.com/([^/]+)/([^/]+)/issues/#', $link, $mRepo)) {
            return null;
        }
        $owner = $mRepo[1];
        $repo = $mRepo[2];
        $commentId = null;
        if (preg_match('#issuecomment-(\d+)#', $link, $m)) {
            $commentId = $m[1];
        }
        if (!$commentId && preg_match('#/issues/comments/(\d+)#', $link, $m)) {
            $commentId = $m[1];
        }
        if (!$commentId) {
            return null;
        }

        return ['owner' => $owner, 'repo' => $repo, 'commentId' => $commentId];
    }

    private function fetchCommentAndIssue(string $owner, string $repo, string $commentId): array
    {
        $comment = $this->githubService->getIssueComment($owner, $repo, $commentId);
        $issue = null;
        $issueUrl = $comment['issue_url'] ?? null;
        if ($issueUrl) {
            $issueNumber = basename($issueUrl);
            $issue = $this->githubService->getIssue($owner, $repo, $issueNumber);
        }

        return ['comment' => $comment, 'issue' => $issue];
    }

    private function buildPayload(array $comment, ?array $sourceIssue, string $sourceOwner, string $sourceRepo, ?string $translateTo, string $originalLink): array
    {
        $sourceIssueNum = $sourceIssue['number'] ?? null;
        $originalAuthor = $comment['user']['login'] ?? 'unknown';
        $originalUrl = $comment['html_url'] ?? $originalLink;
        $commentBody = $comment['body'] ?? '';
        $translated = $translateTo ? $this->translationService->translate($commentBody, $translateTo) : $commentBody;

        $header = "**Original comment by @$originalAuthor**  \n[View source]($originalUrl)";
        if ($sourceIssueNum) {
            $header .= "  \nFrom: `$sourceOwner/$sourceRepo#$sourceIssueNum`";
        }
        $newIssueBody = $header . "\n\n" . $translated;

        $translatedTitle = $sourceIssue && !empty($sourceIssue['title']) ? $this->translationService->translate($sourceIssue['title'], $translateTo) : null;
        $newIssueTitle = "[Comment Copy] " . ($translatedTitle ?? 'Copied comment');

        return ['title' => $newIssueTitle, 'body' => $newIssueBody];
    }
}
