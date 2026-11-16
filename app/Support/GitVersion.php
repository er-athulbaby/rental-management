<?php

namespace App\Support;

/**
 * The checked-out commit, read straight from .git (no git binary, no shell): the footer's version when a deploy has
 * not written APP_VERSION or a VERSION file. Two small file reads; with config:cache it runs once per deploy.
 */
final class GitVersion
{
    public static function read(string $root): ?string
    {
        $git = $root.'/.git';

        // A worktree's .git is a file pointing at the real directory.
        if (is_file($git) && preg_match('/^gitdir: (.+)$/m', (string) file_get_contents($git), $m) === 1) {
            $git = trim($m[1]);
        }

        if (! is_file($git.'/HEAD')) {
            return null;
        }

        $head = trim((string) file_get_contents($git.'/HEAD'));

        if (! str_starts_with($head, 'ref: ')) {
            return self::short($head); // detached HEAD: the hash itself
        }

        $ref = substr($head, 5);
        $common = is_file($git.'/commondir') ? $git.'/'.trim((string) file_get_contents($git.'/commondir')) : $git;

        foreach ([$git, $common] as $dir) {
            if (is_file($dir.'/'.$ref)) {
                return self::short(trim((string) file_get_contents($dir.'/'.$ref)));
            }
        }

        // The ref may only exist packed.
        if (is_file($common.'/packed-refs') && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', (string) file_get_contents($common.'/packed-refs'), $m) === 1) {
            return self::short($m[1]);
        }

        return null;
    }

    private static function short(string $hash): ?string
    {
        return preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? substr($hash, 0, 7) : null;
    }
}
