<?php
$projectRoot = dirname(__DIR__, 2);
require $projectRoot . "/vendor/autoload.php";
if (!extension_loaded("pdo_sqlite")) {
    fwrite(
        STDERR,
        "Cần bật extension pdo_sqlite cho PHP CLI để chạy kiểm thử bộ nhớ.\n",
    );
    exit(1);
}

use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Routing\Redirector;
use Illuminate\Database\Schema\Blueprint;

$app = new Application($projectRoot);
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance(
    "config",
    new Repository([
        "app" => ["timezone" => "UTC", "url" => "https://nfh.test"],
    ]),
);
$app->instance("events", new Dispatcher($app));
$capsule = new Capsule($app);
$capsule->addConnection([
    "driver" => "sqlite",
    "database" => ":memory:",
    "prefix" => "",
    "foreign_key_constraints" => true,
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance("db", $capsule->getDatabaseManager());
$app->bind("db.schema", fn() => $capsule->schema());
$app->instance("translator", new Translator(new ArrayLoader(), "vi"));
$validator = new Factory($app["translator"], $app);
$validator->setPresenceVerifier(
    new DatabasePresenceVerifier($capsule->getDatabaseManager()),
);
$app->instance("validator", $validator);
Request::macro("validate", function (array $rules) use ($validator) {
    return $validator->make($this->all(), $rules)->validate();
});
$router = new Router($app["events"], $app);
$app->instance("router", $router);
$app->instance(
    "url",
    new UrlGenerator($router->getRoutes(), Request::create("https://nfh.test")),
);
$app->instance("redirect", new Redirector($app["url"]));
$app->bind(
    \Illuminate\Contracts\Routing\ResponseFactory::class,
    fn() => new class extends \Illuminate\Routing\ResponseFactory {
        public function __construct() {}
        public function json(
            $data = [],
            $status = 200,
            array $headers = [],
            $options = 0,
        ) {
            return new \Illuminate\Http\JsonResponse(
                $data,
                $status,
                $headers,
                $options,
            );
        }
    },
);

$schema = $capsule->schema();
$capsule
    ->getConnection()
    ->getPdo()
    ->sqliteCreateFunction("CHAR_LENGTH", fn($s) => mb_strlen((string) $s), 1);
$schema->create("products", function (Blueprint $t) {
    $t->id();
    $t->string("product_name");
    $t->boolean("is_show")->default(true);
    $t->timestamps();
});
$schema->create("product_variants", function (Blueprint $t) {
    $t->id();
    $t->foreignId("product_id")->constrained();
    $t->string("variant_name");
    $t->timestamps();
});
$schema->create("product_packages", function (Blueprint $t) {
    $t->id();
    $t->foreignId("variant_id")->constrained("product_variants");
    $t->string("sku")->unique();
    $t->string("size");
    $t->string("unit");
    $t->decimal("price", 12, 2);
    $t->unsignedInteger("quantity_available")->default(0);
    $t->unsignedInteger("reorder_level")->default(5);
    $t->timestamps();
});
foreach (
    array_filter(explode(";", file_get_contents(__DIR__ . "/ai-schema.sql")))
    as $sql
) {
    $capsule->getConnection()->statement($sql);
}
foreach (
    [
        "product_suppliers",
        "cart_items",
        "order_items",
        "inventory_document_items",
        "inventory_transactions",
        "stock_reservations",
        "inventory_lots",
    ]
    as $name
) {
    $schema->create($name, function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger("product_id")->nullable();
        $t->unsignedBigInteger("package_id")->nullable();
    });
}

$source = $projectRoot;
spl_autoload_register(
    function ($class) use ($source): void {
        if (str_starts_with($class, "App\\")) {
            $path =
                $source .
                "/app/" .
                str_replace("\\", "/", substr($class, 4)) .
                ".php";
            if (is_file($path)) {
                require $path;
            }
        }
    },
    true,
    true,
);

use Illuminate\Support\Facades\DB;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Http\Client\Factory as HttpFactory;
$source = $projectRoot;
spl_autoload_register(
    function ($class) use ($source) {
        if (str_starts_with($class, "App\\")) {
            $p =
                $source .
                "/app/" .
                str_replace("\\", "/", substr($class, 4)) .
                ".php";
            if (is_file($p)) {
                require $p;
            }
        }
    },
    true,
    true,
);
$app["config"]->set("ai", require $source . "/config/ai.php");
$app["config"]->set("ai.key", "fixture-test-key-not-a-secret");
$app["config"]->set("ai.chat_model", "fixture-chat");
$app["config"]->set("ai.cache_store", "array");
$app["config"]->set("ai.queue_connection", "database");
$app["config"]->set("ai.dimensions", 3);
$app["config"]->set("ai.collection", "fixture_collection");
$app["config"]->set("app.key", "fixture-key");
$app["config"]->set("cache", [
    "default" => "array",
    "stores" => ["array" => ["driver" => "array", "serialize" => false]],
]);
$app["config"]->set("queue", [
    "default" => "database",
    "connections" => [
        "database" => [
            "driver" => "database",
            "connection" => "default",
            "table" => "jobs",
            "queue" => "ai",
            "retry_after" => 120,
            "after_commit" => false,
        ],
    ],
]);
$app->instance("cache", new CacheManager($app));
$app->bind("cache.store", fn() => $app["cache"]->store());
$app->alias("cache", \Illuminate\Contracts\Cache\Factory::class);
$app->alias("cache.store", \Illuminate\Contracts\Cache\Repository::class);
$httpFixture = new HttpFactory();
$httpFixture->preventStrayRequests();
$app->instance(HttpFactory::class, $httpFixture);
$app->scoped(
    \App\Services\Ai\AiBudget::class,
    fn() => new \App\Services\Ai\AiBudget(),
);
$app->instance("db.transactions", new DatabaseTransactionsManager());
$capsule->getConnection()->setTransactionManager($app["db.transactions"]);
(new QueueServiceProvider($app))->register();
(new BusServiceProvider($app))->register();
$schema->create("jobs", function (Blueprint $t) {
    $t->id();
    $t->string("queue");
    $t->longText("payload");
    $t->unsignedTinyInteger("attempts");
    $t->unsignedInteger("reserved_at")->nullable();
    $t->unsignedInteger("available_at");
    $t->unsignedInteger("created_at");
});
$schema->table("products", function (Blueprint $t) {
    $t->string("brand")->nullable();
    $t->text("description")->nullable();
    $t->text("usage_instructions")->nullable();
    $t->text("safety_warning")->nullable();
    $t->unsignedBigInteger("category_id")->default(1);
    $t->unsignedBigInteger("subcategory_id")->default(1);
    $t->unsignedBigInteger("origin_id")->default(1);
});
$schema->create("categories", function (Blueprint $t) {
    $t->id();
    $t->string("category_name");
});
$schema->create("product_images", function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger("product_id");
    $t->string("image_url");
    $t->boolean("is_primary")->default(false);
    $t->integer("sort_order")->default(0);
});
$schema->table("inventory_lots", function (Blueprint $t) {
    $t->integer("quantity_on_hand")->default(0);
    $t->string("status")->default("available");
    $t->date("expires_on")->nullable();
});
$schema->table("stock_reservations", function (Blueprint $t) {
    $t->integer("quantity")->default(0);
    $t->string("status")->default("active");
});
$capsule
    ->getConnection()
    ->getPdo()
    ->sqliteCreateFunction("GREATEST", fn(...$a) => max($a), -1);
$now = now();
DB::table("categories")->insert([
    "id" => 1,
    "category_name" => "Phân bón (fixture)",
]);
DB::table("products")->insert([
    "id" => 1,
    "product_name" => "Sản phẩm kiểm thử A",
    "is_show" => true,
    "description" => "Nội dung kiểm thử, không phải hướng dẫn sử dụng thật.",
]);
DB::table("product_variants")->insert([
    ["id" => 1, "product_id" => 1, "variant_name" => "Biến thể kiểm thử A"],
    ["id" => 2, "product_id" => 1, "variant_name" => "Biến thể kiểm thử B"],
]);
DB::table("product_packages")->insert([
    [
        "id" => 1,
        "variant_id" => 1,
        "sku" => "TEST-A-100",
        "size" => "100.00",
        "unit" => "ml",
        "price" => "120000.00",
        "quantity_available" => 999,
    ],
    [
        "id" => 2,
        "variant_id" => 1,
        "sku" => "TEST-A-50",
        "size" => "50.00",
        "unit" => "ml",
        "price" => "70000.00",
        "quantity_available" => 999,
    ],
    [
        "id" => 3,
        "variant_id" => 2,
        "sku" => "TEST-B-100",
        "size" => "100.00",
        "unit" => "ml",
        "price" => "50000.00",
        "quantity_available" => 999,
    ],
]);
DB::table("inventory_lots")->insert([
    [
        "package_id" => 1,
        "quantity_on_hand" => 10,
        "status" => "available",
        "expires_on" => now()->addDays(60)->toDateString(),
    ],
    [
        "package_id" => 1,
        "quantity_on_hand" => 100,
        "status" => "blocked",
        "expires_on" => null,
    ],
    [
        "package_id" => 1,
        "quantity_on_hand" => 50,
        "status" => "available",
        "expires_on" => now()->subDay()->toDateString(),
    ],
    [
        "package_id" => 2,
        "quantity_on_hand" => 20,
        "status" => "available",
        "expires_on" => null,
    ],
    [
        "package_id" => 3,
        "quantity_on_hand" => 30,
        "status" => "available",
        "expires_on" => null,
    ],
]);
DB::table("stock_reservations")->insert([
    "package_id" => 1,
    "quantity" => 4,
    "status" => "active",
]);
DB::table("crops")->insert([
    [
        "id" => 1,
        "name" => "Lúa kiểm thử",
        "slug" => "lua-test",
        "aliases" => json_encode(["bắp-test"]),
        "is_active" => true,
    ],
    [
        "id" => 2,
        "name" => "Bắp kiểm thử",
        "slug" => "bap-test",
        "aliases" => json_encode(["bắp-test"]),
        "is_active" => true,
    ],
]);
DB::table("product_purposes")->insert([
    [
        "id" => 1,
        "name" => "Công dụng thử A",
        "slug" => "purpose-a",
        "aliases" => "[]",
        "is_active" => true,
    ],
    [
        "id" => 2,
        "name" => "Công dụng thử B",
        "slug" => "purpose-b",
        "aliases" => "[]",
        "is_active" => true,
    ],
]);
DB::table("crop_growth_stages")->insert([
    [
        "id" => 1,
        "crop_id" => 1,
        "name" => "Giai đoạn thử 1",
        "slug" => "stage-1",
        "aliases" => "[]",
        "is_active" => true,
    ],
    [
        "id" => 2,
        "crop_id" => 2,
        "name" => "Giai đoạn thử 2",
        "slug" => "stage-2",
        "aliases" => "[]",
        "is_active" => true,
    ],
]);
DB::table("product_usage_guides")->insert([
    "id" => 1,
    "variant_id" => 1,
    "crop_id" => 1,
    "purpose_id" => 1,
    "growth_stage_scope" => "all",
    "application_method" => "Mô phỏng kiểm thử",
    "dose_unit" => "ml",
    "dose_per_ha_min" => "250.000000",
    "dose_per_ha_max" => "500.000000",
    "dose_per_liter_min" => null,
    "dose_per_liter_max" => null,
    "water_l_per_ha_min" => null,
    "water_l_per_ha_max" => null,
    "instructions" =>
        "FIXTURE kiểm thử phép tính. KHÔNG dùng nội dung này trên ruộng.",
    "label_source" => "fixture://automated-test-only",
    "is_active" => true,
]);
function req(string $id = "session-A", int $user = 0): \Illuminate\Http\Request
{
    $request = \Illuminate\Http\Request::create(
        "https://nfh.test/api/v1/ai-chat/messages",
        "POST",
        [],
        [],
        [],
        ["REMOTE_ADDR" => "127.0.0.1"],
    );
    $session = new \Illuminate\Session\Store(
        "fixture",
        new \Illuminate\Session\ArraySessionHandler(120),
    );
    $session->setId(str_pad($id, 40, "x"));
    $session->start();
    $request->setLaravelSession($session);
    $request->setUserResolver(
        fn() => $user
            ? new class ($user) {
                public function __construct(private int $id) {}
                public function getAuthIdentifier()
                {
                    return $this->id;
                }
            }
            : null,
    );
    return $request;
}