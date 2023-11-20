<?php
// 它使用了Laravel提供的Route类来定义路由
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
//当用户访问根路径时，将执行匿名函数中的代码，并返回到index.php页面。
Route::get('/', function () {
    return redirect('index.php');
});
//定义一个路由分组，其中包含一组相关的路由。该分组具有以下配置：
//'prefix' => 'web'：定义路由的前缀为web/。
//'middleware' => ['auth.nexus:nexus-web', 'locale']：应用两个中间件，auth.nexus:nexus-web和locale。
Route::group(['prefix' => 'web', 'middleware' => ['auth.nexus:nexus-web', 'locale']], function () {
//    当用户访问web/torrent-approval-page路径时，将执行TorrentController控制器的approvalPage方法。
    Route::get('torrent-approval-page', [\App\Http\Controllers\TorrentController::class, 'approvalPage']);
//    当用户访问web/torrent-approval-logs路径时，将执行TorrentController控制器的approvalLogs方法。
    Route::get('torrent-approval-logs', [\App\Http\Controllers\TorrentController::class, 'approvalLogs']);
//    当用户以POST方式访问web/torrent-approval路径时，将执行TorrentController控制器的approval方法。
    Route::post('torrent-approval', [\App\Http\Controllers\TorrentController::class, 'approval']);
});

//如果当前代码不是在命令行中运行，则会执行以下代码块。
if (!isRunningInConsole()) {
    $passkeyLoginUri = get_setting('security.login_secret');
    if (!empty($passkeyLoginUri) && get_setting('security.login_type') == 'passkey') {
        Route::get("$passkeyLoginUri/{passkey}", [\App\Http\Controllers\AuthenticateController::class, 'passkeyLogin']);
    }
}



