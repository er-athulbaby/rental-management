<?php

use App\Support\GitVersion;

function gitDir(array $files): string
{
    $root = sys_get_temp_dir().'/gitversion-'.bin2hex(random_bytes(6));
    foreach ($files as $path => $content) {
        @mkdir(dirname($root.'/'.$path), 0777, true);
        file_put_contents($root.'/'.$path, $content);
    }

    return $root;
}

test('the version is the short hash of the checked-out commit, read without the git binary', function () {
    $hash = str_repeat('ab12cd3', 5).'ef012';

    expect(GitVersion::read(gitDir(['.git/HEAD' => "ref: refs/heads/main\n", '.git/refs/heads/main' => $hash."\n"])))->toBe('ab12cd3')
        ->and(GitVersion::read(gitDir(['.git/HEAD' => $hash."\n"])))->toBe('ab12cd3') // detached
        ->and(GitVersion::read(gitDir(['.git/HEAD' => "ref: refs/heads/main\n", '.git/packed-refs' => "# pack-refs\n{$hash} refs/heads/main\n"])))->toBe('ab12cd3');
});

test('no repository, a missing ref or a damaged HEAD gives null, never an error', function () {
    expect(GitVersion::read(gitDir(['README' => 'x'])))->toBeNull()
        ->and(GitVersion::read(gitDir(['.git/HEAD' => "ref: refs/heads/gone\n"])))->toBeNull()
        ->and(GitVersion::read(gitDir(['.git/HEAD' => "not a hash\n"])))->toBeNull();
});
