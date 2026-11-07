<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use Mcp\Exception\ToolCallException;

/**
 * Every refusal starts with a stable code a caller can branch on, and ends
 * with a correlation ID that is also written to the audit log rows of the
 * same call: "[BLOCKED_BY_POLICY] message (ref 3f9a1c2b)".
 * Codes: PERMISSION_DENIED, NOT_FOUND, BLOCKED_BY_POLICY, CONFIRM_REQUIRED,
 * UPSTREAM_ERROR, INVALID_INPUT.
 */
final class McpError
{
    public const PERMISSION_DENIED = 'PERMISSION_DENIED';
    public const NOT_FOUND         = 'NOT_FOUND';
    public const BLOCKED_BY_POLICY = 'BLOCKED_BY_POLICY';
    public const CONFIRM_REQUIRED  = 'CONFIRM_REQUIRED';
    public const UPSTREAM_ERROR    = 'UPSTREAM_ERROR';
    public const INVALID_INPUT     = 'INVALID_INPUT';

    /** First matching pattern wins; anything else is INVALID_INPUT. */
    private const RULES = [
        self::CONFIRM_REQUIRED  => '/expectedRecipientCount|you expected \d+ recipients/',
        self::PERMISSION_DENIED => '/role does not allow|access token|needs the permission|Your Mautic user/i',
        self::UPSTREAM_ERROR    => '/^Mautic (did not|could not|rejected)/',
        self::NOT_FOUND         => '/ not found|there is no step|does not exist/i',
        self::BLOCKED_BY_POLICY => '/not published|switched off|disabled on this|operator|published campaigns? |Only unpublished|via MCP|at most \d+|^Not (sent|deleted)|^This would delete|running/i',
    ];

    private static ?string $correlationId = null;

    /** One ID per call: each MCP call is one PHP request. */
    public static function correlationId(): string
    {
        return self::$correlationId ??= bin2hex(random_bytes(4));
    }

    public static function from(string $message, ?string $code = null): ToolCallException
    {
        if (null === $code) {
            $code = self::INVALID_INPUT;
            foreach (self::RULES as $candidate => $pattern) {
                if (1 === preg_match($pattern, $message)) {
                    $code = $candidate;
                    break;
                }
            }
        }

        return new ToolCallException(sprintf('[%s] %s (ref %s)', $code, $message, self::correlationId()));
    }
}
