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
        private TranslationService $translationService,
        private string $defaultOwner,
        private string $defaultRepo,
        private string $defaultLang
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('comment-link', null, InputOption::VALUE_REQUIRED, 'GitHub comment link')
            ->addOption('to-owner', null, InputOption::VALUE_OPTIONAL, 'Target repo owner', $this->defaultOwner)
            ->addOption('to-repo', null, InputOption::VALUE_OPTIONAL, 'Target repo name', $this->defaultRepo)
            ->addOption('lang', null, InputOption::VALUE_OPTIONAL, 'Target language', $this->defaultLang)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run mode');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commentLink = $input->getOption('comment-link');
        $targetOwner = $input->getOption('to-owner');
        $targetRepo = $input->getOption('to-repo');
        $translateTo = $input->getOption('lang');
        $dryRun = $input->getOption('dry-run');

        if (!$commentLink) {
            $output->writeln('<error>--comment-link is required.</error>');
            return Command::FAILURE;
        }

        // Parse owner/repo/commentId from link
        if (!preg_match('#github\.com/([^/]+)/([^/]+)/issues/#', $commentLink, $mRepo)) {
            $output->writeln('<error>Cannot parse owner/repo from --comment-link</error>');
            return Command::FAILURE;
        }
        $sourceOwner = $mRepo[1];
        $sourceRepo = $mRepo[2];
        $commentId = null;
        if (preg_match('#issuecomment-(\d+)#', $commentLink, $m)) $commentId = $m[1];
        if (!$commentId && preg_match('#/issues/comments/(\d+)#', $commentLink, $m)) $commentId = $m[1];
        if (!$commentId) {
            $output->writeln('<error>Cannot extract comment ID from link.</error>');
            return Command::FAILURE;
        }

        // Fetch comment
        $comment = $this->githubService->getIssueComment($sourceOwner, $sourceRepo, $commentId);
        $issueUrl = $comment['issue_url'] ?? null;
        $sourceIssue = $issueUrl ? $this->githubService->getIssue($sourceOwner, $sourceRepo, basename($issueUrl)) : null;
        $sourceIssueNum = $sourceIssue['number'] ?? null;
        $originalAuthor = $comment['user']['login'] ?? 'unknown';
        $originalUrl = $comment['html_url'] ?? $commentLink;
        $commentBody = $comment['body'] ?? '';
        $translated = $translateTo ? $this->translationService->translate($commentBody, $translateTo) : $commentBody;
        $header = "**Original comment by @$originalAuthor**  \n[View source]($originalUrl)";
        if ($sourceIssueNum) $header .= "  \nFrom: `$sourceOwner/$sourceRepo#$sourceIssueNum`";
        $newIssueBody = $header . "\n\n" . $translated;
        $newIssueTitle = "[Comment Copy] " . ($sourceIssue['title'] ?? 'Copied comment');
        $payload = ['title' => $newIssueTitle, 'body' => $newIssueBody];

        if ($dryRun) {
            $output->writeln("[DRY RUN] Would create issue in $targetOwner/$targetRepo");
            $output->writeln("Title: $newIssueTitle\n\n$newIssueBody");
            return Command::SUCCESS;
        }

        $created = $this->githubService->createIssue($targetOwner, $targetRepo, $payload);
        $output->writeln("✅ Created new issue: " . $created['html_url']);
        return Command::SUCCESS;
    }
}

