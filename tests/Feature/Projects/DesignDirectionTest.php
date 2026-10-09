<?php

namespace Tests\Feature\Projects;

use App\Projects\DesignDirection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class DesignDirectionTest extends TestCase
{
    protected string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().'/builder-test-designs-'.Str::lower(Str::random(8));
        File::ensureDirectoryExists($this->folder);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->folder));
        config(['builder.projects.designs' => $this->folder]);
    }

    public function test_looks_are_read_from_the_folder_in_their_order()
    {
        $this->look('sharp', ['name' => 'Sharp', 'order' => 2]);
        $this->look('calm', ['name' => 'Calm', 'order' => 1, 'font' => ['family' => 'Nunito', 'bunny' => 'nunito:400,700']]);

        $looks = DesignDirection::all();

        $this->assertSame(['calm', 'sharp'], array_map(fn (DesignDirection $look) => $look->key, $looks));
        $this->assertSame('Nunito', $looks[0]->font);
        $this->assertSame([400, 700], $looks[0]->weights);
        $this->assertSame([400, 500, 600], $looks[1]->weights);
        $this->assertSame('Sharp', DesignDirection::find('sharp')?->name);
        $this->assertNull(DesignDirection::find('missing'));
    }

    public function test_a_broken_or_incomplete_look_is_skipped()
    {
        $this->look('good', ['name' => 'Good']);
        File::put($this->folder.'/broken.json', '{ not json');
        $this->look('nameless', ['name' => null]);
        $this->look('unsafe-font', ['name' => 'Unsafe', 'font' => ['family' => "Nunito'; } body { color: red"]]);

        $this->assertSame(['good'], array_map(fn (DesignDirection $look) => $look->key, DesignDirection::all()));
    }

    public function test_only_tokens_that_are_safe_to_write_into_css_are_kept()
    {
        $this->look('calm', ['name' => 'Calm', 'light' => [
            'primary' => 'hsl(174 62% 24%)',
            'Bad Name' => 'red',
            'accent' => 'red; } body { display: none',
            'ring' => ['nested'],
        ]]);

        $this->assertSame(['primary' => 'hsl(174 62% 24%)'], DesignDirection::find('calm')?->light);
    }

    public function test_no_looks_are_offered_when_the_folder_is_missing()
    {
        config(['builder.projects.designs' => $this->folder.'/missing']);

        $this->assertSame([], DesignDirection::all());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function look(string $key, array $data): void
    {
        File::put($this->folder."/{$key}.json", json_encode($data + [
            'description' => 'A look.',
            'font' => ['family' => 'Instrument Sans'],
            'light' => ['primary' => 'hsl(0 0% 9%)'],
            'dark' => ['primary' => 'hsl(0 0% 98%)'],
        ], JSON_THROW_ON_ERROR));
    }
}
