<?php

declare(strict_types=1);

namespace Shubo\BogPayment\Test\Unit\Gateway\Error;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\BogPayment\Gateway\Error\UserFacingErrorMapper;

/**
 * Session 8 Priority 2.2 — covers every row in
 * `app/code/Shubo/BogPayment/docs/error-code-map.md` §2 plus locale and
 * raw-message-leakage edge cases.
 */
class UserFacingErrorMapperTest extends TestCase
{
    private ResolverInterface&MockObject $localeResolver;
    private UserFacingErrorMapper $mapper;

    protected function setUp(): void
    {
        $this->localeResolver = $this->createMock(ResolverInterface::class);
        $this->mapper = new UserFacingErrorMapper($this->localeResolver);
    }

    /** Row 11: 0 — network unreachable. */
    public function testZeroStatusFallsThroughToNetworkErrorEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(0);
        self::assertSame(
            'Could not reach the payment system. Please try again in a moment.',
            $e->getMessage(),
        );
    }

    public function testZeroStatusFallsThroughToNetworkErrorKa(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('ka_GE');
        $e = $this->mapper->toLocalizedException(0);
        self::assertSame(
            'გადახდასთან კავშირი ვერ მოხერხდა. სცადეთ ცოტა ხანში.',
            $e->getMessage(),
        );
    }

    /** Row 1: 400 with decline keyword. */
    public function testHttp400DeclineKeywordRoutesToBankDeclinedEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(400, 'Card was declined by issuing bank');
        self::assertSame(
            'Your bank declined the payment. Please try another card or contact your bank.',
            $e->getMessage(),
        );
    }

    public function testHttp400DeclineKeywordRoutesToBankDeclinedKa(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('ka_GE');
        $e = $this->mapper->toLocalizedException(400, 'Insufficient funds');
        self::assertSame(
            'ბანკმა უარყო გადახდა. სცადეთ სხვა ბარათით ან დაუკავშირდით თქვენს ბანკს.',
            $e->getMessage(),
        );
    }

    /** Row 2: 400 plain validation. */
    public function testHttp400PlainRoutesToValidationEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(400, 'Required field missing: amount');
        self::assertSame('Payment data is invalid. Please try again.', $e->getMessage());
    }

    public function testHttp400EmptyMessageRoutesToValidation(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(400);
        self::assertSame('Payment data is invalid. Please try again.', $e->getMessage());
    }

    /** Row 3: 401 OAuth error. */
    public function testHttp401RoutesToConfigErrorEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(401);
        self::assertSame('Payment system configuration error. Please contact support.', $e->getMessage());
    }

    /** Row 4: 402 payment required. */
    public function testHttp402RoutesToBankDeclinedEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(402);
        self::assertSame('Your bank declined the payment. Try another card.', $e->getMessage());
    }

    /** Row 5: 403 forbidden. */
    public function testHttp403RoutesToMethodUnavailableEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(403);
        self::assertSame('This payment method is not available for this order.', $e->getMessage());
    }

    /** Row 6: 404 not found. */
    public function testHttp404RoutesToPaymentNotFoundEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(404);
        self::assertSame('Payment not found. Please start again.', $e->getMessage());
    }

    /** Row 7: 409 idempotency conflict. */
    public function testHttp409RoutesToAlreadyProcessedEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(409);
        self::assertSame(
            'This payment has already been processed. Please check your orders.',
            $e->getMessage(),
        );
    }

    /** Row 8: 422 invalid state. */
    public function testHttp422RoutesToInvalidStateEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(422);
        self::assertSame(
            'This action has already been completed or is in an invalid state.',
            $e->getMessage(),
        );
    }

    /** Row 9: 429 rate limit. */
    public function testHttp429RoutesToBusyEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(429);
        self::assertSame('The system is busy. Please try again in a moment.', $e->getMessage());
    }

    /** Row 10: 500-504 upstream outage. */
    public function testHttp500RoutesToBankUnavailableEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(500);
        self::assertSame(
            'Bank payment system temporarily unavailable. Please try later.',
            $e->getMessage(),
        );
    }

    public function testHttp503RoutesToBankUnavailableKa(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('ka_GE');
        $e = $this->mapper->toLocalizedException(503);
        self::assertSame(
            'ბანკის გადახდის სისტემა დროებით მიუწვდომელია. სცადეთ მოგვიანებით.',
            $e->getMessage(),
        );
    }

    /** Row 12: other 4xx. */
    public function testHttp418RoutesToGenericClientErrorEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(418);
        self::assertSame(
            'Payment couldn\'t be completed. Please try again or contact support.',
            $e->getMessage(),
        );
    }

    /** Default — unmapped (e.g. 599 / 199 / 301). */
    public function testHttp301RoutesToDefaultEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(301);
        self::assertSame(
            'Payment couldn\'t be completed. Please try again or contact support.',
            $e->getMessage(),
        );
    }

    public function testHttp599RoutesToDefaultEn(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(599);
        self::assertSame(
            'Payment couldn\'t be completed. Please try again or contact support.',
            $e->getMessage(),
        );
    }

    /** Locale: russian falls through to English (TBC convention mirrored). */
    public function testRussianLocaleFallsThroughToEnglish(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('ru_RU');
        $e = $this->mapper->toLocalizedException(404);
        self::assertSame('Payment not found. Please start again.', $e->getMessage());
    }

    /** Critical contract: raw error message NEVER appears in user-facing copy. */
    public function testRawMessageNeverLeaksIntoUserCopy(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $secretLeak = 'Internal trace: order_id=42, customer_id=99, secret_token=abc123';
        $e = $this->mapper->toLocalizedException(500, $secretLeak);
        self::assertStringNotContainsString('order_id', $e->getMessage());
        self::assertStringNotContainsString('secret_token', $e->getMessage());
        self::assertStringNotContainsString('abc123', $e->getMessage());
        self::assertStringNotContainsString('99', $e->getMessage());
    }

    /** errorCode parameter never leaks either. */
    public function testErrorCodeNeverLeaksIntoUserCopy(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e = $this->mapper->toLocalizedException(400, '', 'BOG_ERR_INTERNAL_REF_xyz');
        self::assertStringNotContainsString('BOG_ERR_INTERNAL_REF_xyz', $e->getMessage());
        self::assertStringNotContainsString('xyz', $e->getMessage());
    }

    /** Decline keyword detection is case-insensitive across all keywords. */
    public function testDeclineKeywordsAreCaseInsensitive(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');

        $keywords = ['DECLINED', 'rejected', 'INSUFFICIENT', 'Card EXPIRED', 'fraud detected'];
        foreach ($keywords as $kw) {
            $e = $this->mapper->toLocalizedException(400, $kw);
            self::assertSame(
                'Your bank declined the payment. Please try another card or contact your bank.',
                $e->getMessage(),
                "Keyword '{$kw}' should route to bank-decline copy",
            );
        }
    }

    /** Type contract — every call returns a fresh exception, never null. */
    public function testReturnsLocalizedExceptionInstance(): void
    {
        $this->localeResolver->method('getLocale')->willReturn('en_US');
        $e1 = $this->mapper->toLocalizedException(500);
        $e2 = $this->mapper->toLocalizedException(500);
        self::assertInstanceOf(LocalizedException::class, $e1);
        self::assertNotSame($e1, $e2, 'Mapper must return a fresh exception per call');
    }
}
