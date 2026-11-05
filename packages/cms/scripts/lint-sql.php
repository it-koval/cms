#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * composer lint:sql: fails on MySQL constructs in the SQL of src/ (migrations included).
 *
 * Only string tokens are scanned, so docblocks and comments can name MySQL freely. Pass directories or files to scan something else:
 *   php scripts/lint-sql.php src tests/Core
 */

/** @var array<string, string> rule name => regular expression (case-insensitive, matched inside string literals) */
const RULES = [
    'JSON_EXTRACT()' => '/\bJSON_EXTRACT\s*\(/i',
    'JSON_UNQUOTE()' => '/\bJSON_UNQUOTE\s*\(/i',
    'JSON_CONTAINS()' => '/\bJSON_CONTAINS\s*\(/i',
    'ON DUPLICATE KEY' => '/\bON\s+DUPLICATE\s+KEY\b/i',
    'INSERT IGNORE' => '/\bINSERT\s+IGNORE\b/i',
    'CRC32()' => '/\bCRC32\s*\(/i',
    'IFNULL()' => '/\bIFNULL\s*\(/i',
    'GET_LOCK()' => '/\bGET_LOCK\s*\(/i',
    'RELEASE_LOCK()' => '/\bRELEASE_LOCK\s*\(/i',
    'LOCATE()' => '/\bLOCATE\s*\(/i',
    'UTC_TIMESTAMP()' => '/\bUTC_TIMESTAMP\s*\(/i',
    'CURDATE()' => '/\bCURDATE\s*\(/i',
    'DATE_SUB()' => '/\bDATE_SUB\s*\(/i',
    'DATE_ADD()' => '/\bDATE_ADD\s*\(/i',
    'TIMESTAMPDIFF()' => '/\bTIMESTAMPDIFF\s*\(/i',
    'CAST(… AS CHAR)' => '/\bAS\s+CHAR\b/i',
    'ENGINE=' => '/\bENGINE\s*=/i',
    'utf8mb4' => '/utf8mb4/i',
    'AUTO_INCREMENT' => '/\bAUTO_INCREMENT\b/i',
    'TINYINT' => '/\bTINYINT\b/i',
    'LONGTEXT' => '/\bLONGTEXT\b/i',
    'DATETIME column' => '/\bDATETIME\b/i',
    'backtick-quoted identifier' => '/`[A-Za-z_][A-Za-z0-9_]*`/',
    'information_schema.COLUMNS (MySQL columns)' => '/\b(COLUMN_TYPE|COLUMN_KEY|COLUMN_COMMENT)\b/',
];

/** Rules that need the string to look like SQL: a backtick in an info() text and 'datetime' as the type of a field are not MySQL. A lone backtick, used to quote an identifier, counts as SQL. */
const DDL_RULES = ['backtick-quoted identifier', 'TINYINT', 'LONGTEXT', 'DATETIME column', 'AUTO_INCREMENT'];
const SQL_CONTEXT = '/\b(?:SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|FROM|WHERE|TABLE|COLLATE|ENGINE|INDEX)\b|^([\'"])`\1$/';

$targets = \array_slice($argv, 1) ?: [\dirname(__DIR__) . '/src'];
$hits = 0;
foreach ($targets as $target) {
    $files = \is_dir($target)
        ? new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)), '/\.php$/')
        : [new SplFileInfo($target)];
    foreach ($files as $file) {
        $path = (string) $file;
        $source = \file_get_contents($path);
        if (false === $source) {
            \fwrite(\STDERR, "cannot read $path\n");
            exit(2);
        }
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!$token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE])) {
                continue;
            }
            $sql = 1 === \preg_match(SQL_CONTEXT, $token->text);
            foreach (RULES as $name => $pattern) {
                if (!$sql && \in_array($name, DDL_RULES, true)) {
                    continue;
                }
                if (1 === \preg_match($pattern, $token->text, $m, \PREG_OFFSET_CAPTURE)) {
                    $line = $token->line + \substr_count($token->text, "\n", 0, $m[0][1]);
                    $snippet = \trim(\substr($token->text, \max(0, $m[0][1] - 30), 90));
                    echo "$path:$line: $name: …{$snippet}…\n";
                    ++$hits;
                }
            }
        }
    }
}

if ($hits > 0) {
    echo "\n$hits MySQL construct(s) found. ITK CMS runs on PostgreSQL only: use jsonb operators (->>, #>>), ON CONFLICT, COALESCE, hashtext(), pg_try_advisory_lock() and double-quoted identifiers.\n";
    exit(1);
}
echo 'lint:sql: no MySQL constructs in ' . \implode(', ', $targets) . "\n";
