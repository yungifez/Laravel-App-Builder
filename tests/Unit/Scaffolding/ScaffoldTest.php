<?php

namespace Tests\Unit\Scaffolding;

use App\Runs\Plan;
use App\Scaffolding\FieldType;
use App\Scaffolding\Scaffold;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScaffoldTest extends TestCase
{
    /**
     * @return array<string, array{array{name: string, type: string, required: bool, choices: list<string>, of: string|null}, string, list<string>, string|null, string}>
     */
    public static function fields(): array
    {
        return [
            'required string' => [self::field('title', 'string'), "\$table->string('title');", ['required', 'string', 'max:255'], null, 'fake()->words(3, true)'],
            'optional text' => [self::field('notes', 'text', false), "\$table->text('notes')->nullable();", ['nullable', 'string', 'max:65535'], null, 'fake()->paragraph()'],
            'integer' => [self::field('seats', 'integer'), "\$table->integer('seats');", ['required', 'integer'], 'integer', 'fake()->numberBetween(1, 100)'],
            'decimal' => [self::field('price', 'decimal'), "\$table->decimal('price', 10, 2);", ['required', 'numeric'], 'decimal:2', 'fake()->randomFloat(2, 1, 1000)'],
            'yes or no starts as no' => [self::field('paid', 'boolean', false), "\$table->boolean('paid')->default(false);", ['sometimes', 'boolean'], 'boolean', 'fake()->boolean()'],
            'date' => [self::field('due_on', 'date'), "\$table->date('due_on');", ['required', 'date'], 'date', 'fake()->date()'],
            'date and time' => [self::field('starts_at', 'datetime'), "\$table->dateTime('starts_at');", ['required', 'date'], 'datetime', 'fake()->dateTime()'],
            'email' => [self::field('email', 'email'), "\$table->string('email');", ['required', 'email', 'max:255'], null, 'fake()->safeEmail()'],
            'choice' => [self::field('status', 'choice', choices: ['pending', 'confirmed']), "\$table->string('status');", ['required', 'in:pending,confirmed'], null, "fake()->randomElement(['pending', 'confirmed'])"],
            'required link' => [self::field('customer', 'belongs_to', of: 'Customer'), "\$table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();", ['required', 'exists:customers,id'], null, 'Customer::factory()'],
            'optional link' => [self::field('room', 'belongs_to', false, of: 'MeetingRoom'), "\$table->foreignId('room_id')->nullable()->constrained('meeting_rooms')->nullOnDelete();", ['nullable', 'exists:meeting_rooms,id'], null, 'MeetingRoom::factory()'],
        ];
    }

    /**
     * @param  array{name: string, type: string, required: bool, choices: list<string>, of: string|null}  $field
     * @param  list<string>  $rules
     */
    #[DataProvider('fields')]
    public function test_each_kind_of_field_maps_to_one_column_rule_cast_and_value(array $field, string $column, array $rules, ?string $cast, string $fake)
    {
        $type = FieldType::from($field['type']);

        $this->assertSame($column, $type->column($field));
        $this->assertSame($rules, $type->rules($field));
        $this->assertSame($cast, $type->cast());
        $this->assertSame($fake, $type->fake($field));
    }

    public function test_every_part_of_a_new_record_comes_from_the_one_shape()
    {
        $files = (new Scaffold)->files([self::booking(), self::customer()], [], new DateTimeImmutable('2026-10-02 09:00:00'));

        // The customer's table is created first, since bookings link to it.
        $this->assertSame([
            'database/migrations/2026_10_02_090000_create_customers_table.php',
            'app/Models/Customer.php',
            'database/factories/CustomerFactory.php',
            'app/Http/Requests/StoreCustomerRequest.php',
            'database/migrations/2026_10_02_090001_create_bookings_table.php',
            'app/Models/Booking.php',
            'database/factories/BookingFactory.php',
            'app/Http/Requests/StoreBookingRequest.php',
        ], array_keys($files));

        foreach ($files as $path => $contents) {
            token_get_all($contents, TOKEN_PARSE);
            $this->assertStringStartsWith("<?php\n\n", $contents, $path);
        }

        $model = $files['app/Models/Booking.php'];
        $this->assertStringContainsString("protected \$fillable = ['customer_id', 'starts_at', 'status'];", $model);
        $this->assertStringContainsString("'starts_at' => 'datetime',", $model);
        $this->assertStringContainsString("return \$this->belongsTo(Customer::class, 'customer_id');", $model);
        $this->assertStringContainsString("Schema::create('bookings'", $files['database/migrations/2026_10_02_090001_create_bookings_table.php']);
        $this->assertStringContainsString("'customer_id' => Customer::factory(),", $files['database/factories/BookingFactory.php']);
        $this->assertStringContainsString("'status' => ['required', 'in:pending,confirmed,cancelled'],", $files['app/Http/Requests/StoreBookingRequest.php']);
        // Nobody may create one until a policy says who may.
        $this->assertStringContainsString("return Gate::allows('create', Booking::class);", $files['app/Http/Requests/StoreBookingRequest.php']);
    }

    public function test_an_app_that_names_fillable_fields_with_the_attribute_gets_the_attribute()
    {
        $model = (new Scaffold)->files([self::customer()], [], new DateTimeImmutable, attributes: true)['app/Models/Customer.php'];

        $this->assertStringContainsString("#[Fillable(['name', 'email'])]\nclass Customer extends Model", $model);
        $this->assertStringContainsString('use Illuminate\Database\Eloquent\Attributes\Fillable;', $model);
        $this->assertStringNotContainsString('$fillable', $model);
        $this->assertStringEndsWith("    use HasFactory;\n}\n", $model);
    }

    public function test_a_record_the_app_already_has_is_left_to_the_coding_agent()
    {
        $scaffold = new Scaffold;

        $this->assertSame([], $scaffold->files([self::customer()], ['app/Models/Customer.php'], new DateTimeImmutable));
        $this->assertSame([], $scaffold->files([self::customer()], ['database/migrations/2025_01_01_000000_create_customers_table.php'], new DateTimeImmutable));
    }

    public function test_the_plan_keeps_only_a_shape_that_holds_together()
    {
        $this->assertSame([self::booking()], Plan::dataShape([self::booking()]));

        $this->assertSame([], Plan::dataShape('Bookings'), 'not a list of records');
        $this->assertSame([], Plan::dataShape([['name' => 'booking', 'fields' => self::booking()['fields']]]), 'not a model name');
        $this->assertSame([], Plan::dataShape([['name' => 'Booking', 'fields' => [self::field('id', 'integer')]]]), 'a column Laravel adds itself');
        $this->assertSame([], Plan::dataShape([['name' => 'Booking', 'fields' => [self::field('kind', 'colour')]]]), 'an unknown type');
        $this->assertSame([], Plan::dataShape([['name' => 'Booking', 'fields' => [self::field('status', 'choice', choices: ['open'])]]]), 'a choice of one');
        $this->assertSame([], Plan::dataShape([['name' => 'Booking', 'fields' => [self::field('status', 'choice', choices: ["open'; drop", 'closed'])]]]), 'a value that is not a plain word');
        $this->assertSame([], Plan::dataShape([['name' => 'Booking', 'fields' => [self::field('customer', 'belongs_to')]]]), 'a link to nothing');
        $this->assertSame([], Plan::dataShape([['name' => 'Booking', 'fields' => [self::field('customer', 'belongs_to', of: 'Customer'), self::field('customer_id', 'integer')]]]), 'two fields in one column');
        $this->assertSame([], Plan::dataShape([self::customer(), self::customer()]), 'one record twice');
    }

    public function test_who_may_do_what_gives_the_policy_and_the_tests_that_guard_it()
    {
        $access = ['view' => 'creator', 'create' => 'signed_in', 'update' => 'creator', 'delete' => 'everyone'];
        $files = (new Scaffold)->files([['name' => 'Booking', 'fields' => [self::field('user', 'belongs_to', of: 'User'), self::field('starts_at', 'datetime')], 'access' => $access]], [], new DateTimeImmutable);

        $policy = $files['app/Policies/BookingPolicy.php'];
        $test = $files['tests/Feature/BookingAccessTest.php'];
        token_get_all($policy, TOKEN_PARSE);
        token_get_all($test, TOKEN_PARSE);

        $this->assertStringContainsString("public function view(User \$user, Booking \$booking): bool\n    {\n        return \$booking->user()->is(\$user);", $policy);
        // A list has no creator: anyone signed in may ask for one, and it holds only theirs.
        $this->assertStringContainsString("The list holds only the booking records the user added.\n     */\n    public function viewAny(User \$user): bool\n    {\n        return true;", $policy);
        $this->assertStringContainsString('public function create(User $user): bool', $policy);
        $this->assertStringContainsString('public function delete(?User $user, Booking $booking): bool', $policy);

        $this->assertStringContainsString('public function test_a_guest_cannot_see_a_booking(): void', $test);
        $this->assertStringContainsString("\$this->assertTrue(Gate::forUser(\$record->user)->allows('view', \$record));", $test);
        $this->assertStringContainsString("\$this->assertFalse(Gate::forUser(User::factory()->create())->allows('update', \$record));", $test);
        $this->assertStringContainsString('public function test_anyone_signed_in_can_add_bookings(): void', $test);
        $this->assertStringContainsString('public function test_anyone_can_remove_a_booking(): void', $test);

        // Who added it comes from the signed-in user, not from the form.
        $this->assertStringNotContainsString("'user_id'", $files['app/Http/Requests/StoreBookingRequest.php']);
        $this->assertStringContainsString("'starts_at' => ['required', 'date'],", $files['app/Http/Requests/StoreBookingRequest.php']);

        $this->assertArrayNotHasKey('app/Policies/CustomerPolicy.php', (new Scaffold)->files([self::customer()], [], new DateTimeImmutable));
    }

    public function test_every_action_that_changes_a_new_record_asks_its_policy()
    {
        $access = ['view' => 'creator', 'create' => 'signed_in', 'update' => 'creator', 'delete' => 'creator'];
        $record = ['name' => 'BookingSlot', 'fields' => [self::field('user', 'belongs_to', of: 'User'), self::field('starts_at', 'datetime')], 'access' => $access];
        $routes = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::middleware('auth')->group(function () {\n    Route::view('home', 'home');\n});\n";

        $reached = (new Scaffold)->routes([$record], ['app/Http/Controllers/Controller.php'], ['routes/web.php' => $routes]);

        $this->assertSame(['app/Http/Controllers/BookingSlotController.php', 'app/Http/Requests/UpdateBookingSlotRequest.php', 'routes/web.php'], array_keys($reached['files']));
        $this->assertSame([], $reached['notes']);

        foreach ($reached['files'] as $path => $contents) {
            token_get_all($contents, TOKEN_PARSE);
        }

        $controller = $reached['files']['app/Http/Controllers/BookingSlotController.php'];
        $this->assertStringContainsString('class BookingSlotController extends Controller', $controller);
        $this->assertStringContainsString('public function store(StoreBookingSlotRequest $request): RedirectResponse', $controller);
        $this->assertStringContainsString('public function update(UpdateBookingSlotRequest $request, BookingSlot $bookingSlot): RedirectResponse', $controller);
        $this->assertStringContainsString("public function destroy(BookingSlot \$bookingSlot): RedirectResponse\n    {\n        Gate::authorize('delete', \$bookingSlot);", $controller);
        // Who added it is the signed-in user, never the form.
        $this->assertStringContainsString("BookingSlot::create([...\$request->validated(), 'user_id' => \$request->user()?->id]);", $controller);

        $update = $reached['files']['app/Http/Requests/UpdateBookingSlotRequest.php'];
        $this->assertStringContainsString("return Gate::allows('update', \$this->route('booking_slot'));", $update);
        $this->assertStringNotContainsString('use App\\Models\\BookingSlot;', $update);
        $this->assertStringNotContainsString("'user_id'", $update);

        $this->assertStringContainsString("    Route::resource('booking-slots', BookingSlotController::class)->only(['store', 'update', 'destroy']);\n});", $reached['files']['routes/web.php']);
    }

    public function test_an_app_without_a_group_for_signed_in_people_gets_the_controller_and_a_note()
    {
        $reached = (new Scaffold)->routes([self::customer()], [], ['routes/web.php' => "<?php\n\nRoute::view('/', 'welcome');\n"]);

        $this->assertSame(['app/Http/Controllers/CustomerController.php', 'app/Http/Requests/UpdateCustomerRequest.php'], array_keys($reached['files']));
        $this->assertStringContainsString("class CustomerController\n{", $reached['files']['app/Http/Controllers/CustomerController.php']);
        $this->assertStringContainsString('Customer::create($request->validated());', $reached['files']['app/Http/Controllers/CustomerController.php']);
        $this->assertSame(["Customer: routes/web.php has no plain group of routes for signed-in people (Route::middleware('auth')->group(function () { … })), so their routes were not added. Add them where the app keeps routes for signed-in people."], $reached['notes']);
    }

    public function test_a_controller_or_route_the_app_has_is_never_written_over()
    {
        $scaffold = new Scaffold;
        $routes = ['routes/web.php' => "<?php\n\nRoute::middleware('auth')->group(function () {\n    Route::resource('customers', ClientController::class);\n});\n"];

        $this->assertSame(['files' => [], 'notes' => ['Customer: the app already has a route customers in routes/web.php, so no controller or routes were written for it. Add its actions beside that route.']], $scaffold->routes([self::customer()], [], $routes));
        $this->assertSame(['files' => [], 'notes' => ['Customer: the app already has app/Http/Controllers/CustomerController.php, so no controller or routes were written for it.']], $scaffold->routes([self::customer()], ['app/Http/Controllers/CustomerController.php'], []));
        $this->assertSame(['files' => [], 'notes' => []], $scaffold->routes([self::customer()], ['app/Models/Customer.php'], $routes), 'a record the app has');
    }

    public function test_access_is_kept_only_when_it_can_be_checked()
    {
        $access = ['view' => 'creator', 'create' => 'signed_in', 'update' => 'creator', 'delete' => 'creator'];
        $owned = ['name' => 'Booking', 'fields' => [self::field('user', 'belongs_to', of: 'User')], 'access' => $access];

        $this->assertSame([$owned], Plan::dataShape([$owned]));
        $this->assertSame([], Plan::dataShape([[...self::customer(), 'access' => $access]]), 'a creator with no record of who added it');
        $this->assertSame([], Plan::dataShape([[...$owned, 'access' => [...$access, 'delete' => 'admins']]]), 'someone the policy cannot name');
        $this->assertSame([], Plan::dataShape([[...$owned, 'access' => ['view' => 'everyone']]]), 'an action left out');
    }

    public function test_choices_are_kept_only_for_a_choice_and_the_linked_model_only_for_a_link()
    {
        $shape = Plan::dataShape([['name' => 'Note', 'fields' => [['name' => 'body', 'type' => 'text', 'required' => true, 'choices' => ['a', 'b'], 'of' => 'User']]]]);

        $this->assertSame([['name' => 'Note', 'fields' => [self::field('body', 'text')], 'access' => null]], $shape);
    }

    /**
     * @param  list<string>  $choices
     * @return array{name: string, type: string, required: bool, choices: list<string>, of: string|null}
     */
    protected static function field(string $name, string $type, bool $required = true, array $choices = [], ?string $of = null): array
    {
        return ['name' => $name, 'type' => $type, 'required' => $required, 'choices' => $choices, 'of' => $of];
    }

    /**
     * @return array{name: string, fields: list<array{name: string, type: string, required: bool, choices: list<string>, of: string|null}>}
     */
    protected static function booking(): array
    {
        return ['name' => 'Booking', 'fields' => [
            self::field('customer', 'belongs_to', of: 'Customer'),
            self::field('starts_at', 'datetime'),
            self::field('status', 'choice', choices: ['pending', 'confirmed', 'cancelled']),
        ], 'access' => null];
    }

    /**
     * @return array{name: string, fields: list<array{name: string, type: string, required: bool, choices: list<string>, of: string|null}>}
     */
    protected static function customer(): array
    {
        return ['name' => 'Customer', 'fields' => [self::field('name', 'string'), self::field('email', 'email')], 'access' => null];
    }
}
