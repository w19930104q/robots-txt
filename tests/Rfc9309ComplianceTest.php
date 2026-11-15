<?php

declare(strict_types=1);

use Crwlr\RobotsTxt\RobotsTxt;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RFC 9309 (Robots Exclusion Protocol) compliant group selection and path matching,
 * parsing the robots.txt contents from local fixture files.
 */
final class Rfc9309ComplianceTest extends TestCase
{
    private static function parseFixture(string $fixture): RobotsTxt
    {
        $content = file_get_contents(__DIR__ . '/fixtures/' . $fixture);

        if ($content === false) {
            self::fail('Failed to read fixture file: ' . $fixture);
        }

        return RobotsTxt::parse($content);
    }

    public function test_product_token_matches_user_agent_string_with_version(): void
    {
        $robotsTxt = self::parseFixture('version-string.txt');

        $userAgent = 'FooBot/2.1 (+https://example.com/bot)';

        // The specific FooBot groups match the product token within the full user agent string,
        // so the wildcard group's "Disallow: /" must not be applied (RFC 9309 2.3.).
        $this->assertTrue($robotsTxt->isAllowed('/', $userAgent));
        $this->assertTrue($robotsTxt->isAllowed('/anything', $userAgent));
    }

    public function test_specific_group_is_preferred_over_wildcard_group(): void
    {
        $robotsTxt = self::parseFixture('version-string.txt');

        // For any other bot only the wildcard group matches and its rules apply.
        $this->assertFalse($robotsTxt->isAllowed('/', 'OtherBot/1.0'));
        $this->assertFalse($robotsTxt->isAllowed('/anything', 'OtherBot/1.0'));

        // For FooBot the wildcard group's rules are ignored, only its own group rules apply.
        $this->assertFalse($robotsTxt->isAllowed('/private', 'FooBot/2.1'));
        $this->assertFalse($robotsTxt->isAllowed('/private/page', 'FooBot/2.1'));
    }

    public function test_repeated_groups_with_the_same_product_token_are_combined(): void
    {
        $robotsTxt = self::parseFixture('version-string.txt');

        // /also-private is disallowed in a second, separate "User-agent: FooBot" group.
        $this->assertFalse($robotsTxt->isAllowed('/also-private', 'FooBot/2.1'));
    }

    public function test_longer_allow_rule_wins_over_shorter_disallow_rule(): void
    {
        $robotsTxt = self::parseFixture('version-string.txt');

        $this->assertTrue($robotsTxt->isAllowed('/private/public', 'FooBot/2.1'));
    }

    public function test_percent_encoded_ascii_characters_match_unencoded_equivalents(): void
    {
        $robotsTxt = self::parseFixture('percent-encoding.txt');

        // The rule "/p%61th" is decoded to "/path" before comparison (RFC 9309 2.2.2.).
        $this->assertFalse($robotsTxt->isAllowed('/path', 'AnyBot'));
        $this->assertFalse($robotsTxt->isAllowed('/p%61th', 'AnyBot'));
        $this->assertFalse($robotsTxt->isAllowed('/path/deeper', 'AnyBot'));

        // The more specific allow rule wins.
        $this->assertTrue($robotsTxt->isAllowed('/path/public', 'AnyBot'));

        // Non ASCII characters stay percent encoded, hex case doesn't matter.
        $this->assertFalse($robotsTxt->isAllowed('/dir/%E2%82%AC', 'AnyBot'));
        $this->assertFalse($robotsTxt->isAllowed('/dir/%e2%82%ac', 'AnyBot'));

        $this->assertTrue($robotsTxt->isAllowed('/other', 'AnyBot'));
    }

    public function test_rules_match_against_path_and_query_string(): void
    {
        $robotsTxt = self::parseFixture('query-string.txt');

        $this->assertFalse($robotsTxt->isAllowed('/search?q=foo', 'AnyBot'));
        $this->assertFalse($robotsTxt->isAllowed('/search?page=2', 'AnyBot'));

        // The longer allow rule wins over the shorter disallow rule.
        $this->assertTrue($robotsTxt->isAllowed('/search?page=home', 'AnyBot'));

        // No query string => the "/search?*" rule doesn't match.
        $this->assertTrue($robotsTxt->isAllowed('/search', 'AnyBot'));

        // The "$" sign marks the end of a matching path.
        $this->assertFalse($robotsTxt->isAllowed('/locked', 'AnyBot'));
        $this->assertTrue($robotsTxt->isAllowed('/locked/unlocked', 'AnyBot'));
    }

    public function test_rules_match_from_the_beginning_of_the_path(): void
    {
        $robotsTxt = self::parseFixture('boundary-paths.txt');

        $this->assertFalse($robotsTxt->isAllowed('/foo', 'AnyBot'));
        $this->assertFalse($robotsTxt->isAllowed('/foo/bar', 'AnyBot'));

        // Octet prefix match: "/foo" is also a prefix of "/foobar" (RFC 9309 2.2.2.).
        $this->assertFalse($robotsTxt->isAllowed('/foobar', 'AnyBot'));

        // But "/foo" somewhere in the middle of the path must not match.
        $this->assertTrue($robotsTxt->isAllowed('/x/foo', 'AnyBot'));
        $this->assertTrue($robotsTxt->isAllowed('/x/foo/bar', 'AnyBot'));
        $this->assertTrue($robotsTxt->isAllowed('/', 'AnyBot'));
        $this->assertTrue($robotsTxt->isAllowed('/fo', 'AnyBot'));
    }
}
