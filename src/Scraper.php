<?php

declare(strict_types=1);

namespace PhpCfdi\CsfPdfScraper;

use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use GuzzleHttp\Client;
use PhpCfdi\CsfPdfScraper\Contracts\BrowserClientInterface;
use PhpCfdi\CsfPdfScraper\Exceptions\InvalidCaptchaException;
use PhpCfdi\CsfPdfScraper\Exceptions\InvalidCredentialsException;
use PhpCfdi\CsfPdfScraper\Exceptions\PDFDownloadException;
use PhpCfdi\CsfPdfScraper\Exceptions\SatScraperException;
use PhpCfdi\ImageCaptchaResolver\CaptchaImage;
use PhpCfdi\ImageCaptchaResolver\CaptchaResolverInterface;

class Scraper
{
    public function __construct(
        private Credentials $credentials,
        private CaptchaResolverInterface $captchaResolver,
        private BrowserClientInterface $browserClient,
        private Client $client,
        private bool $isFiel = false,
        private int $timeout = 30
    ) {
    }

    /**
     * @throws InvalidCaptchaException
     * @throws InvalidCredentialsException
     */
    private function login(): void
    {
        if($this->isFiel) $this->loginFIEL();
        else $this->loginCIEC();
    }

    //convertir de PEM a DER

    private function loginFIEL(): void{
        //get into login page
        $this->browserClient->get(URL::LOGIN_URL);
        try {
            $this->browserClient->waitFor('#buttonFiel', $this->timeout);
        } catch (TimeoutException | NoSuchElementException $exception) {
            throw new SatScraperException(sprintf('The %s page does not load as expected', URL::LOGIN_URL), 0, $exception);
        }

        //click en e-firma
        $this->browserClient->executeScript("#buttonFiel");

        //verificar si si cargo la ventana de inicio de sesion con e-firma (FIEL)
        try {
            $this->browserClient->waitFor('#txtCertificate', $this->timeout);
        } catch (TimeoutException | NoSuchElementException $exception) {
            throw new SatScraperException(sprintf('The %s page does not load as expected', URL::LOGIN_URL), 0, $exception);
        }

        //obtener el formulario del .cer el .key y la clave
        $form = $this->browserClient->getCrawler()
            ->selectButton('submit')
            ->form();
        //tenemos 4 campos (solo 3 de ellos editables)
        //El primero recibe el .cer (txtCertificate)
        //el segundo el .key (txtPrivateKey)
        //el tercero la llave privada (privateKeyPassword)
        //por cada campo que requiera un archivo, hay que hacer un tempnam()

        $cert_b64 = $this->credentials->getFcert();
        $key_b64 = $this->credentials->getFkey();
        $pass = $this->credentials->getPass();

        //Decodear de base64
        $cert_decoded = base64_decode($cert_b64);
        $key_decoded = base64_decode($key_b64);

        // Colocar el contenido decodificado en archivos temporales.cer y .key
        $cert_file = tempnam(sys_get_temp_dir(), "cert");
        file_put_contents($cert_file.'.cer', $cert_decoded);
        $key_file = tempnam(sys_get_temp_dir(), "key");
        file_put_contents($key_file.'.key', $key_decoded);

        $form->setValues([
            'txtCertificate' => $cert_file.'.cer',
            'txtPrivateKey' => $key_file.'.key',
            'privateKeyPassword' => $pass
        ]);

        $this->browserClient->submit($form);

        $html = $this->browserClient->getCrawler()->html();
    }

    private function loginCIEC(): void{
        $this->browserClient->get(URL::LOGIN_URL);
        try {
            $this->browserClient->waitFor('#divCaptcha', $this->timeout);
        } catch (TimeoutException | NoSuchElementException $exception) {
            throw new SatScraperException(sprintf('The %s page does not load as expected', URL::LOGIN_URL), 0, $exception);
        }

        $captcha = $this->browserClient->getCrawler()
            ->filter('#divCaptcha > img')
            ->first();

        $image = CaptchaImage::newFromInlineHtml($captcha->attr('src'));

        $value = $this->captchaResolver->resolve($image);

        $form = $this->browserClient->getCrawler()
            ->selectButton('submit')
            ->form();

        $form->setValues([
            'Ecom_User_ID' => $this->credentials->getRfc(),
            'Ecom_Password' => $this->credentials->getCiec(),
            'userCaptcha' => $value->getValue(),
        ]);

        $this->browserClient->submit($form);

        $html = $this->browserClient->getCrawler()->html();
        if (str_contains($html, 'El Certificado seleccionado es')) {
            throw new InvalidCredentialsException('El certificado es invalido.');
        }

        if (str_contains($html, 'La clave privada que seleccio')) {
            throw new InvalidCredentialsException('La clave privada es invalida');
        }

        if (str_contains($html, 'Certificado, clave privada, o contraseña de clave privada')) {
            throw new InvalidCredentialsException('Algun dato de la e-firma es invalido (probablemente la contraseña de la llave privada)');
        }
    }

    private function buildConstancia(): void
    {
        $this->browserClient->get(URL::MAIN_URL);
        try {
            $this->browserClient->waitFor('#idPanelReimpAcuse_header', $this->timeout);
        } catch (TimeoutException | NoSuchElementException $exception) {
            throw new SatScraperException(sprintf('The %s page does not load as expected', URL::MAIN_URL), 0, $exception);
        }

        $form = $this->browserClient->getCrawler()
            ->selectButton('Generar Constancia')
            ->form();

        $this->browserClient->submit($form);
    }

    private function logout(): void
    {
        $this->browserClient->get(URL::LOGOUT_URL);
        $this->browserClient->waitFor('#campo-busqueda', $this->timeout);
        $this->browserClient->getCrawler()
            ->selectButton('Cerrar sesión')
            ->click();
    }

    public function download(): string
    {
        $this->login();
        $this->buildConstancia();

        $cookieParser = new CookieParser($this->browserClient->getCookieJar());

        try {
            $response = $this->client->request('GET',
                URL::DOWNLOAD_CONSTANCIA_URL, [
                    'cookies' => $cookieParser->guzzleCookieJar(),
                ]);
        } catch (\Throwable $exception) {
            throw $exception;
        }

        // TODO: quitar esta linea cuando ya no lo descargue 2 veces
        @unlink('SAT.pdf');

        // TODO: hacer logout correctamente
        // $this->logout();*/

        return $response->getBody()->__toString();
    }
}
