<?php
/*
 * Copyright (c) 2025. IOWEB TECHNOLOGIES
 */

namespace App\Command;

use App\Service\GithubService;
use App\Service\TranslationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'copy:issue',
    description: 'Copy a GitHub issue and its comments to another repo.'
)]
class CopyIssueCommand extends Command
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
            ->addArgument('issue-number', InputArgument::REQUIRED, 'Source issue number')
            ->addOption('to-owner', null, InputOption::VALUE_OPTIONAL, 'Target repo owner', $this->defaultOwner)
            ->addOption('to-repo', null, InputOption::VALUE_OPTIONAL, 'Target repo name', $this->defaultRepo)
            ->addOption('lang', null, InputOption::VALUE_OPTIONAL, 'Target language', $this->defaultLang)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run mode');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issueNumber = $input->getArgument('issue-number');
        $targetOwner = $input->getOption('to-owner');
        $targetRepo = $input->getOption('to-repo');
        $translateTo = $input->getOption('lang');
        $dryRun = $input->getOption('dry-run');

        // Source repo defaults from env
        $sourceOwner = $this->defaultOwner;
        $sourceRepo = $this->defaultRepo;

        // Fetch issue
        $issue = $this->githubService->getIssue($sourceOwner, $sourceRepo, $issueNumber);
        if (isset($issue['pull_request'])) {
            $output->writeln("❌ Issue #$issueNumber is a pull request. Skipping.");
            return Command::FAILURE;
        }
        $newIssue = [
            'title' => $this->translationService->translate($issue['title'], $translateTo),
            'body' => "**Copied from original issue:** [#{$issue['number']}]({$issue['html_url']})\n\n" .
                $this->translationService->translate($issue['body'] ?? '', $translateTo),
        ];
        if (!empty($issue['labels'])) {
            $newIssue['labels'] = array_map(fn($l) => $l['name'], $issue['labels']);
        }
        if ($dryRun) {
            $output->writeln("[DRY RUN] Would create issue in $targetOwner/$targetRepo:");
            $output->writeln(json_encode($newIssue, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            $newIssueNumber = 9999;
        } else {
            $created = $this->githubService->createIssue($targetOwner, $targetRepo, $newIssue);
            $newIssueNumber = $created['number'];
            $output->writeln("✅ Issue #$issueNumber copied to: {$created['html_url']}");
        }
        // Copy comments
        $comments = $this->githubService->getIssueComments($sourceOwner, $sourceRepo, $issueNumber);
        if (!empty($comments)) {
            foreach ($comments as $comment) {
                $translatedComment = $this->translationService->translate($comment['body'], $translateTo);
                $body = "**Original comment by {$comment['user']['login']}**:\n\n" . $translatedComment;
                $commentPayload = ['body' => $body];
                if ($dryRun) {
                    $output->writeln("[DRY RUN] Would copy comment by {$comment['user']['login']}:");
                    $output->writeln($body . "\n");
                } else {
                    $this->githubService->createComment($targetOwner, $targetRepo, $newIssueNumber, $commentPayload);
                    $output->writeln("🗨️ Copied a comment by {$comment['user']['login']}");
                }
            }
        } else {
            $output->writeln("ℹ️ No comments to copy.");
        }
        return Command::SUCCESS;
    }
}
