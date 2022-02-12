<?php

use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Purpose;
use ParagonIE\Paseto\Keys\SymmetricKey;
use ParagonIE\Paseto\Protocol\Version2;

class Oo_create_paseto_token
{
    protected $token = null;

    public function __construct($sharedKey, string $footer = '', string $exp = 'P01D' )
    {
        $sharedKey = new ParagonIE\Paseto\Keys\SymmetricKey($sharedKey);
        $token = ParagonIE\Paseto\Builder::getLocal($sharedKey, new ParagonIE\Paseto\Protocol\Version2);
        $token = (new ParagonIE\Paseto\Builder())
            ->setKey($sharedKey)
            ->setVersion(new ParagonIE\Paseto\Protocol\Version2)
            ->setPurpose(ParagonIE\Paseto\Purpose::local())
            ->setIssuedAt()
            ->setNotBefore()
            ->setExpiration((new \DateTime())->add(new \DateInterval($exp)))
            ->setFooter($footer);
        $this->token = $token; // Converts automatically to a string
    }
    public function get_signed_token()
    {
        return $this->token;
    }
}
