<?php
namespace KatalysMerchantPlugin;

use DateTime;
use DateInterval;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Exception\InvalidKeyException;
use ParagonIE\Paseto\Exception\InvalidPurposeException;
use ParagonIE\Paseto\Exception\PasetoException;
use ParagonIE\Paseto\Purpose;
use ParagonIE\Paseto\Keys\SymmetricKey;
use ParagonIE\Paseto\Protocol\Version2;

class Oo_create_paseto_token
{
    protected $token = null;

  /**
   * @param $sharedKey
   * @param string $footer
   * @param string $exp
   * @throws InvalidKeyException
   * @throws InvalidPurposeException
   * @throws PasetoException
   */
  public function __construct($sharedKey, $footer = '', $exp = 'P01D' )
    {
        $sharedKey = new SymmetricKey($sharedKey);
        $token = Builder::getLocal($sharedKey, new Version2);
        $token = (new Builder())
            ->setKey($sharedKey)
            ->setVersion(new Version2)
            ->setPurpose(Purpose::local())
            ->setIssuedAt()
            ->setNotBefore()
            ->setExpiration((new DateTime())->add(new DateInterval($exp)))
            ->setFooter($footer);
        $this->token = $token; // Converts automatically to a string
    }
    public function get_signed_token()
    {
        return $this->token;
    }
}
