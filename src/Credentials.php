<?php

declare(strict_types=1);

namespace PhpCfdi\CsfPdfScraper;

class Credentials
{
    public function __construct(private string $rfc = '', private string $ciec = '', private string $fcert = '', private string $fkey = '', private string $pass = '')
    {
    }

    public function getRfc(): string
    {
        return $this->rfc;
    }

    public function getCiec(): string
    {
        return $this->ciec;
    }

    /**
     * @return string
     */
    public function getFcert(): string
    {
        return $this->fcert;
    }

    /**
     * @return string
     */
    public function getFkey(): string
    {
        return $this->fkey;
    }

    /**
     * @return string
     */
    public function getPass(): string
    {
        return $this->pass;
    }
}
