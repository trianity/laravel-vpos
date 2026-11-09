<?php

namespace Trianity\Vpos\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Validator;
use Trianity\Vpos\Contracts\CheckoutDataProvider;
use Trianity\Vpos\Exceptions\InvalidPaymentRequestException;
use Trianity\Vpos\Exceptions\VposException;
use Trianity\Vpos\Vpos;

class PaymentController extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function __construct(protected CheckoutDataProvider $checkoutData) {}

    /**
     * Handle a Payment Init request.
     */
    public function paymentInit(Request $request): RedirectResponse
    {
        $validator = Validator::make(
            $request->all(),
            ['orderReference' => ['required', 'string', 'max:100']],
        );

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        $orderReference = (string) $request->input('orderReference');

        try {
            $checkout = $this->checkoutData->resolve($orderReference, $request);
        } catch (VposException|InvalidPaymentRequestException) {
            return redirect()->route('vpos.error')->with('status', trans('vpos::payment.paymentinit_failed'));
        }

        try {
            $vpos = app(Vpos::class);
            $echoResponse = $vpos->echo();
        } catch (VposException) {
            return redirect()->route('vpos.apierror')->with('status', trans('vpos::payment.paymentinit_failed'));
        }
        if (($echoResponse['resultCode'] ?? null) !== 0 || ($echoResponse['resultMessage'] ?? null) !== 'OK') {
            return redirect()->route('vpos.apierror')->with('status', trans('vpos::payment.paymentinit_failed'));
        }

        try {
            $response = $vpos->init($checkout);
        } catch (VposException|InvalidPaymentRequestException) {
            return redirect()->route('vpos.error')->with('status', trans('vpos::payment.paymentinit_failed'));
        }

        $payId = $response['payId'] ?? null;
        if (($response['resultCode'] ?? null) !== 0 || ($response['resultMessage'] ?? null) !== 'OK'
            || ($response['paymentStatus'] ?? null) !== 1 || ! is_string($payId) || $payId === '') {
            return redirect()->route('vpos.error')->with('status', trans('vpos::payment.paymentinit_failed'));
        }

        $this->checkoutData->persistPaymentReference($orderReference, $payId);

        try {
            return redirect()->away($vpos->processUrl($payId));
        } catch (VposException|InvalidPaymentRequestException) {
            return redirect()->route('vpos.error')->with('status', trans('vpos::payment.paymentinit_failed'));
        }
    }
}
