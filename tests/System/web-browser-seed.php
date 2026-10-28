<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== realpath(getenv('WEB_SMOKE_DIRECTORY').'/web-e2e.sqlite')
    || !str_starts_with(str_replace('\\', '/', realpath(getenv('WEB_SMOKE_DIRECTORY'))).'/',str_replace('\\', '/', storage_path('app/private/browser-checks')).'/')) {
    throw new RuntimeException('Browser checks require their isolated SQLite database.');
}
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$agent = App\Models\User::factory()->create(['role'=>'AGENT','phone'=>'0770000001','name'=>'Browser Agent','password'=>Illuminate\Support\Facades\Hash::make('E2EOnly!123')]);
$rep = App\Models\User::factory()->create(['role'=>'DSR_REP','phone'=>'0770000002','name'=>'Browser Rep']);
$branch = App\Models\Branch::create(['name'=>'Browser Warehouse','code'=>'E2E']);
$destinationBranch = App\Models\Branch::create(['name'=>'Browser Destination Warehouse','code'=>'E2E-DEST']);
$route = App\Models\Route::create(['branch_id'=>$branch->id,'route_name'=>'Browser Route','route_code'=>'E2E']);
App\Models\DsrProfile::create(['user_id'=>$rep->id,'rep_code'=>'E2E','branch_id'=>$branch->id,'assigned_route_id'=>$route->id,'dsr_status'=>'ACTIVE']);
$shop = App\Models\Shop::create(['route_id'=>$route->id,'shop_code'=>'E2E','shop_name'=>'Browser Shop','created_by'=>$rep->id]);
$category = App\Models\Category::create(['name'=>'Browser Category','code'=>'E2E']);
$item = App\Models\Item::create(['name'=>'Browser Item','code'=>'E2E','category_id'=>$category->id,'purchase_price'=>50,'selling_price'=>100,'status'=>'ACTIVE']);
App\Models\Stock::create(['branch_id'=>$branch->id,'item_id'=>$item->id,'batch_number'=>'E2E','quantity'=>20,'unit_price'=>100,'unit_cost'=>50,'total_value'=>2000]);
foreach ([['Browser Source','001',500],['Browser Destination','002',100]] as [$name,$number,$balance]) {
    App\Models\BankAccount::create(['bank_name'=>$name,'account_number'=>$number,'account_name'=>'Browser Agency','currency'=>'LKR','opening_balance'=>$balance,'current_balance'=>$balance,'is_active'=>true]);
}
file_put_contents(getenv('WEB_SMOKE_DIRECTORY').'/web-e2e-fixture.json', json_encode(['branch'=>$branch->id,'destinationBranch'=>$destinationBranch->id,'rep'=>$rep->id,'item'=>$item->id,'shop'=>$shop->id,'repToken'=>$rep->createToken('browser-mobile')->plainTextToken]));
echo "Isolated browser fixture ready.\n";
