<?php

namespace Tests\Unit\Scaffolding;

use App\Scaffolding\RouteFile;
use PHPUnit\Framework\TestCase;

class RouteFileTest extends TestCase
{
    public function test_a_route_for_signed_in_people_goes_in_their_group_with_its_import()
    {
        $routes = <<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;

            Route::inertia('/', 'Welcome')->name('home');

            Route::middleware(['auth', 'verified'])->group(function () {
                Route::inertia('dashboard', 'Dashboard')->name('dashboard');
            });

            require __DIR__.'/settings.php';

            PHP;

        $this->assertSame(<<<'PHP'
            <?php

            use App\Http\Controllers\BookingSlotController;
            use Illuminate\Support\Facades\Route;

            Route::inertia('/', 'Welcome')->name('home');

            Route::middleware(['auth', 'verified'])->group(function () {
                Route::inertia('dashboard', 'Dashboard')->name('dashboard');
                Route::resource('booking-slots', BookingSlotController::class)->only(['store', 'update', 'destroy']);
            });

            require __DIR__.'/settings.php';

            PHP, RouteFile::add($routes, [['name' => 'BookingSlot', 'uri' => RouteFile::uri('BookingSlot'), 'signed_in' => true]]));
    }

    public function test_a_group_written_the_older_way_gets_routes_in_its_own_style()
    {
        // Array options, tab indents, full class names and a brace in a string.
        $routes = "<?php\n\nRoute::get('/', fn () => '{');\n\nRoute::group(['middleware' => 'auth:sanctum'], function () {\n\tRoute::get('home', [\\App\\Http\\Controllers\\HomeController::class, 'show']);\n\n\tif (true) {\n\t\tRoute::view('about', 'about');\n\t}\n});\n";

        $added = (string) RouteFile::add($routes, [['name' => 'Booking', 'uri' => 'bookings', 'signed_in' => true]]);

        $this->assertStringEndsWith("\t}\n\tRoute::resource('bookings', \\App\\Http\\Controllers\\BookingController::class)->only(['store', 'update', 'destroy']);\n});\n", $added);
        $this->assertStringNotContainsString('use App\\Http\\Controllers\\BookingController;', $added);
        token_get_all($added, TOKEN_PARSE);
    }

    public function test_a_route_anyone_may_use_goes_at_the_end()
    {
        $added = (string) RouteFile::add("<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::view('/', 'welcome');\n", [['name' => 'Note', 'uri' => 'notes', 'signed_in' => false]]);

        $this->assertSame("<?php\n\nuse App\\Http\\Controllers\\NoteController;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::view('/', 'welcome');\n\nRoute::resource('notes', NoteController::class)->only(['store', 'update', 'destroy']);\n", $added);
    }

    public function test_routes_are_left_out_where_no_plain_group_for_signed_in_people_exists()
    {
        $signedIn = [['name' => 'Booking', 'uri' => 'bookings', 'signed_in' => true]];

        $this->assertNull(RouteFile::add("<?php\n\nRoute::view('/', 'welcome');\n", $signedIn), 'no group');
        $this->assertNull(RouteFile::add("<?php\n\nRoute::middleware('auth')->prefix('admin')->group(function () {\n    Route::view('/', 'admin');\n});\n", $signedIn), 'a group that moves its routes');
        $this->assertNull(RouteFile::add("<?php\n\nRoute::middleware('auth')->group(base_path('routes/app.php'));\n", $signedIn), 'a group in another file');
        $this->assertNull(RouteFile::add("<?php\n\nRoute::middleware('guest')->group(function () {\n    Route::view('login', 'login');\n});\n", $signedIn), 'a group for guests');
    }

    public function test_a_route_the_app_has_is_found_by_its_name_or_its_resource()
    {
        $this->assertSame('bookings.store in routes/web.php', RouteFile::taken(['routes/web.php' => "<?php\n\nRoute::post('book', Book::class)->name('bookings.store');\n"], 'bookings'));
        $this->assertSame('bookings in routes/api.php', RouteFile::taken(['routes/api.php' => "<?php\n\nRoute::apiResource('bookings', BookingController::class);\n"], 'bookings'));
        $this->assertNull(RouteFile::taken(['routes/web.php' => "<?php\n\nRoute::resource('admin-bookings', AdminBookingController::class)->name('admin.bookings');\n"], 'bookings'));
    }
}
