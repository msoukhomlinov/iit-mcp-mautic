<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Controller;

use GuzzleHttp\Psr7\ServerRequest;
use MauticPlugin\IntellectItMcpBundle\Service\McpScopes;
use MauticPlugin\IntellectItMcpBundle\Service\McpServerFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streamable HTTP endpoint of the MCP server and its RFC 9728 metadata. */
final class McpController
{
    public function __construct(
        private readonly McpServerFactory $factory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mcpAction(Request $request): Response
    {
        if (!$this->factory->isConfigured()) {
            return new JsonResponse(['error' => 'MCP needs the OAuth authorization server (mautic.oauth) to be configured.'], 503);
        }

        try {
            $psr = (new ServerRequest(
                $request->getMethod(),
                $request->getUri(),
                $request->headers->all(),
                $request->getContent(),
                '1.1',
                $request->server->all()
            ))->withQueryParams($request->query->all());

            $response = $this->factory->server()->run($this->factory->transport($psr));
        } catch (\Throwable $e) {
            $this->logger->error('intellectit_mcp: request failed', ['exception' => $e]);

            return new JsonResponse(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Internal error']], 500);
        }

        return $this->toSymfony($this->widenScopeChallenge($response));
    }

    /**
     * A 403 insufficient_scope names only the scopes the refused call needs
     * (e.g. "mcp:read mcp:contacts:write"). Clients that re-authorize with
     * exactly those lose the scopes they had (mcp:write), and the next call
     * fails the other way round. Name all regular scopes instead, plus what
     * the call needs (so an opt-in scope is only asked for when required).
     */
    private function widenScopeChallenge(ResponseInterface $response): ResponseInterface
    {
        $header = $response->getHeaderLine('WWW-Authenticate');
        if (403 !== $response->getStatusCode() || !str_contains($header, 'insufficient_scope')) {
            return $response;
        }
        $required = preg_match('/scope="([^"]*)"/', $header, $m) ? preg_split('/\s+/', trim($m[1])) : [];
        $scopes   = array_values(array_unique(array_filter(array_merge(McpScopes::REGULAR, $required ?: []))));
        $value    = str_contains($header, 'scope="')
            ? (string) preg_replace('/scope="[^"]*"/', 'scope="'.implode(' ', $scopes).'"', $header)
            : $header.', scope="'.implode(' ', $scopes).'"';

        return $response->withHeader('WWW-Authenticate', $value);
    }

    public function resourceMetadataAction(Request $request): Response
    {
        if (!$this->factory->isConfigured()) {
            return new JsonResponse(['error' => 'not configured'], 503);
        }
        $response = new JsonResponse($this->factory->resourceMetadata()->jsonSerialize(), 200, [
            'Cache-Control'               => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
        ]);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES);

        return $response;
    }

    /** SSE responses are streamed; everything else is buffered. */
    private function toSymfony(ResponseInterface $psr): Response
    {
        $headers = $psr->getHeaders();
        $body    = $psr->getBody();

        if (str_starts_with($psr->getHeaderLine('Content-Type'), 'text/event-stream')) {
            $response = new StreamedResponse(static function () use ($body): void {
                while (!$body->eof()) {
                    $chunk = $body->read(8192);
                    if ('' === $chunk) {
                        continue;
                    }
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }, $psr->getStatusCode(), $headers);
            $response->headers->set('X-Accel-Buffering', 'no');

            return $response;
        }

        return new Response((string) $body, $psr->getStatusCode(), $headers);
    }
}
