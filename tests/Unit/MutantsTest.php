<?php

use App\Features\Mutants;

it('makes one fixed mistake per line and never inside a string or comment', function () {
    expect(Mutants::mistake('        $this->authorize(\'update\', $team);'))->toBe('')
        ->and(Mutants::mistake('        $invoice->save();'))->toBe('')
        ->and(Mutants::mistake('        if ($user->id === $team->owner_id) {'))->toBe('        if ($user->id !== $team->owner_id) {')
        ->and(Mutants::mistake('        return $total >= 100 && $paid;'))->toBe('        return $total < 100 && $paid;')
        ->and(Mutants::mistake('        return $count > 0;'))->toBe('        return $count <= 0;')
        ->and(Mutants::mistake('        $items->map(fn ($item) => $item->price);'))->toBeNull()
        ->and(Mutants::mistake('        return true;'))->toBe('        return false;')
        ->and(Mutants::mistake('        if (! $user->isAdmin()) {'))->toBe('        if ( $user->isAdmin()) {')
        ->and(Mutants::mistake("        \$label = 'a === b';"))->toBeNull()
        ->and(Mutants::mistake('        // return $a === $b;'))->toBeNull()
        ->and(Mutants::mistake('        $invoice = Invoice::create($data)'))->toBeNull('A call cut across lines is not left out.');
});

it('chooses run lines outside the tests, a file at a time, up to the limit', function () {
    $patch = "diff --git a/app/B.php b/app/B.php\n--- a/app/B.php\n+++ b/app/B.php\n@@ -0,0 +1,3 @@\n+if (\$a === \$b) {\n+return true;\n+}\n"
        ."diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -0,0 +1,2 @@\n+\$x->save();\n+return \$a < \$b;\n"
        ."diff --git a/tests/Feature/ATest.php b/tests/Feature/ATest.php\n--- a/tests/Feature/ATest.php\n+++ b/tests/Feature/ATest.php\n@@ -0,0 +1 @@\n+\$this->assertTrue(\$a === \$b);\n"
        ."diff --git a/database/migrations/x.php b/database/migrations/x.php\n--- a/database/migrations/x.php\n+++ b/database/migrations/x.php\n@@ -0,0 +1 @@\n+\$table->string('a')->nullable(false);\n";
    $isTest = fn (string $path) => str_starts_with($path, 'tests/');
    $run = fn (string $file, int $line) => ! ($file === 'app/A.php' && $line === 2);

    expect(Mutants::choose($patch, $run, 2, $isTest))->toBe([
        ['file' => 'app/A.php', 'line' => 1, 'was' => '$x->save();', 'now' => ''],
        ['file' => 'app/B.php', 'line' => 1, 'was' => 'if ($a === $b) {', 'now' => 'if ($a !== $b) {'],
    ])->and(array_column(Mutants::choose($patch, $run, 10, $isTest), 'line'))->toBe([1, 1, 2]);
});
