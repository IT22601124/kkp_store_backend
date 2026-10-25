<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ActiveApiUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($request->route()->getActionMethod() === 'logout') {
            return $next($request);
        }
        if (strtoupper($user->status) !== 'ACTIVE') {
            return response()->json(['success' => false, 'code' => 'ACCOUNT_INACTIVE', 'message' => 'Your account is inactive.'], 403);
        }
        if ($user->role === 'DSR_REP') {
            abort_if($user->dsrProfile && $user->dsrProfile->dsr_status === 'INACTIVE', 403, 'Your rep profile is inactive.');
            // A mobile token must not gain agent privileges through the general API aliases.
            $actions = [
                'AuthController' => ['checkToken'],
                'v1\\MobileRepAuthController' => ['me', 'updateAvatar', 'logout', 'checkToken', 'updateFcmToken'],
                'v1\\MobileItemController' => ['index', 'show'],
                'v1\\MobileShopController' => ['index', 'show', 'store', 'update', 'destroy', 'getShopsByRoute', 'getTodaySalesWithShops', 'getShopTodaySales', 'getTodayVisitedShops', 'getCreditShops'],
                'v1\\UserShopController' => ['getShopsByCreatedBy'],
                'ShopController' => ['index', 'show', 'store', 'update', 'destroy', 'settleCredit'],
                'SaleController' => ['index', 'show', 'store'],
                'v1\\MobileSaleController' => ['index', 'show', 'store', 'getTodaySales', 'getShopCreditSales', 'getDailySettlementSheet', 'getDailySettlementHistory', 'storeDailySettlementSheet'],
                'StockRequestController' => ['index', 'show', 'store'],
                'RepStockController' => ['index', 'show', 'getByRep'],
                'StockController' => ['fetchStockByBranch'],
                'RouteController' => ['index'],
                'BankTransferController' => ['uploadSlip', 'attachSlipToSale', 'attachSlipToPayment'],
                'v1\\MobileNotificationController' => ['index', 'markRead', 'markAllRead'],
            ];
            [$controller, $method] = explode('@', $request->route()->getActionName()) + [null, null];
            $controller = str_replace('App\\Http\\Controllers\\', '', $controller);
            abort_unless(in_array($method, $actions[$controller] ?? [], true), 403, 'This action requires an agent or administrator account.');
        }

        return $next($request);
    }
}
