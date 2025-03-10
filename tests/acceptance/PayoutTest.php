<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use TrueLayer\Constants\Currencies;
use TrueLayer\Interfaces\Beneficiary\ExternalAccountBeneficiaryInterface;
use TrueLayer\Interfaces\MerchantAccount\MerchantAccountInterface;
use TrueLayer\Interfaces\Payment\AuthorizationFlow\Action\RedirectActionInterface;
use TrueLayer\Interfaces\Payment\PaymentSettledInterface;
use TrueLayer\Interfaces\Payout\PaymentSourceBeneficiaryInterface;
use TrueLayer\Interfaces\Payout\PayoutRetrievedInterface;

\it('creates a closed loop payout', function () {
    $helper = \paymentHelper();

    $account = Arr::first(
        $helper->client()->getMerchantAccounts(),
        fn (MerchantAccountInterface $account) => $account->getCurrency() === 'GBP'
    );

    $merchantBeneficiary = $helper->merchantBeneficiary($account);

    $created = $helper->create(
        $helper->bankTransferMethod($merchantBeneficiary), $helper->user(), $account->getCurrency()
    );

    \client()->startPaymentAuthorization($created, 'https://console.truelayer.com/redirect-page');
    \client()->submitPaymentProvider($created, 'mock-payments-gb-redirect');

    /** @var RedirectActionInterface $next */
    $next = $created->getDetails()->getAuthorizationFlowNextAction();
    \bankAction($next->getUri(), 'Execute');
    \sleep(120);

    /* @var PaymentSettledInterface $payment */
    $payment = $created->getDetails();

    $client = \client();

    $payoutBeneficiary = $client->payoutBeneficiary()->paymentSource()
        ->paymentSourceId($payment->getPaymentSource()->getId())
        ->reference('Test reference')
        ->userId($payment->getUserId());

    $response = $client->payout()
        ->amountInMinor(1)
        ->currency(Currencies::GBP)
        ->merchantAccountId($account->getId())
        ->beneficiary($payoutBeneficiary)
        ->create();

    \expect($response->getId())->toBeString();

    /** @var PayoutRetrievedInterface $payout */
    $payout = $client->getPayout($response->getId());

    \expect($payout)->toBeInstanceOf(PayoutRetrievedInterface::class);
    \expect($payout->getCurrency())->toBe(Currencies::GBP);
    \expect($payout->getAmountInMinor())->toBe(1);
    \expect($payout->getCreatedAt())->toBeInstanceOf(DateTimeInterface::class);

    /** @var PaymentSourceBeneficiaryInterface $beneficiary */
    $beneficiary = $payout->getBeneficiary();

    \expect($beneficiary)->toBeInstanceOf(PaymentSourceBeneficiaryInterface::class);
    \expect($beneficiary->getPaymentSourceId())->toBe($payment->getPaymentSource()->getId());
    \expect($beneficiary->getReference())->toBe('Test reference');
    \expect($beneficiary->getUserId())->toBe($payment->getUserId());
});

\it('creates an open loop payout', function () {
    $helper = \paymentHelper();

    $account = Arr::first(
        $helper->client()->getMerchantAccounts(),
        fn (MerchantAccountInterface $account) => $account->getCurrency() === 'GBP'
    );

    $merchantBeneficiary = $helper->merchantBeneficiary($account);

    $created = $helper->create(
        $helper->bankTransferMethod($merchantBeneficiary), $helper->user(), $account->getCurrency()
    );

    \client()->startPaymentAuthorization($created, 'https://console.truelayer.com/redirect-page');
    \client()->submitPaymentProvider($created, 'mock-payments-gb-redirect');

    /** @var RedirectActionInterface $next */
    $next = $created->getDetails()->getAuthorizationFlowNextAction();
    \bankAction($next->getUri(), 'Execute');
    \sleep(15);

    /* @var PaymentSettledInterface $payment */
    $payment = $created->getDetails();

    $client = \client();

    $payoutBeneficiary = $client->payoutBeneficiary()->externalAccount()
        ->accountIdentifier(
            $client->accountIdentifier()->iban()->iban('GB29NWBK60161331926819')
        )
        ->accountHolderName('Test name')
        ->reference('Test reference');

    $response = $client->payout()
        ->amountInMinor(1)
        ->currency(Currencies::GBP)
        ->merchantAccountId($account->getId())
        ->beneficiary($payoutBeneficiary)
        ->create();

    \expect($response->getId())->toBeString();

    /** @var PayoutRetrievedInterface $payout */
    $payout = $client->getPayout($response->getId());

    \expect($payout)->toBeInstanceOf(PayoutRetrievedInterface::class);
    \expect($payout->getCurrency())->toBe(Currencies::GBP);
    \expect($payout->getAmountInMinor())->toBe(1);
    \expect($payout->getCreatedAt())->toBeInstanceOf(DateTimeInterface::class);

    /** @var ExternalAccountBeneficiaryInterface $beneficiary */
    $beneficiary = $payout->getBeneficiary();

    \expect($beneficiary)->toBeInstanceOf(ExternalAccountBeneficiaryInterface::class);
    \expect($beneficiary->getAccountHolderName())->toBe('Test name');
    \expect($beneficiary->getReference())->toBe('Test reference');
});
