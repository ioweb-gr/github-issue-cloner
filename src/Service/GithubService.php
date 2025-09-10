<?php
/*
 * Copyright (c) 2025. IOWEB TECHNOLOGIES
 */

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GithubService
{
    private $client;
    private $token;

    public function __construct(HttpClientInterface $client, string $githubToken)
    {
        $this->client = $client;
        $this->token = $githubToken;
    }

    public function getIssueComment(string $owner, string $repo, string $commentId): array
    {
        $url = "https://api.github.com/repos/$owner/$repo/issues/comments/$commentId";
        $response = $this->client->request('GET', $url, [
            'headers' => $this->getHeaders(),
        ]);
        return $response->toArray();
    }

    public function getIssue(string $owner, string $repo, string $issueNumber): array
    {
        $url = "https://api.github.com/repos/$owner/$repo/issues/$issueNumber";
        $response = $this->client->request('GET', $url, [
            'headers' => $this->getHeaders(),
        ]);
        return $response->toArray();
    }

    public function createIssue(string $owner, string $repo, array $payload): array
    {
        $url = "https://api.github.com/repos/$owner/$repo/issues";
        $response = $this->client->request('POST', $url, [
            'headers' => $this->getHeaders(),
            'json' => $payload,
        ]);
        return $response->toArray();
    }

    public function getIssueComments(string $owner, string $repo, string $issueNumber): array
    {
        $url = "https://api.github.com/repos/$owner/$repo/issues/$issueNumber/comments";
        $response = $this->client->request('GET', $url, [
            'headers' => $this->getHeaders(),
        ]);
        return $response->toArray();
    }

    public function createComment(string $owner, string $repo, string $issueNumber, array $payload): array
    {
        $url = "https://api.github.com/repos/$owner/$repo/issues/$issueNumber/comments";
        $response = $this->client->request('POST', $url, [
            'headers' => $this->getHeaders(),
            'json' => $payload,
        ]);
        return $response->toArray();
    }

    private function getHeaders(): array
    {
        return [
            'User-Agent' => 'Symfony',
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
    }
}

