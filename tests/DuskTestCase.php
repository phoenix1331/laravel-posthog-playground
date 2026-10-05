<?php

declare(strict_types=1);

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\TestCase as BaseTestCase;

/**
 * Browser sessions driven by the Selenium "browser" container (Compose profile
 * "dusk"), which shares the app container's network: Dusk reaches Selenium
 * at localhost:4444 and the browser reaches the app at localhost:8000. Nothing is started locally and the database is never reset:
 * these scripts browse the seeded app like real supporters.
 */
abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Chromium runs in the selenium container, so there is no local
     * ChromeDriver to start.
     */
    public static function prepare(): void {}

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments([
            '--window-size=1366,900',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            '--disable-gpu',
            '--headless=new',
        ]);

        return RemoteWebDriver::create(
            env('DUSK_DRIVER_URL', 'http://localhost:4444'),
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options),
        );
    }

    /**
     * The app as the browser reaches it. Selenium shares the app container's
     * network, so this is localhost on the container port, not APP_URL (the
     * host-side address).
     */
    protected function baseUrl(): string
    {
        return rtrim((string) env('DUSK_APP_URL', 'http://localhost:8000'), '/');
    }
}
