<?php
/**
 * Created by PhpStorm.
 * User: johan
 * Date: 2017-03-03
 * Time: 19:24
 */
declare(strict_types = 1);

namespace Vinnia\Shipping\FedEx;


use DateTimeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Money\Currency;
use Money\Money;
use Psr\Http\Message\ResponseInterface;
use Vinnia\Shipping\Address;
use Vinnia\Shipping\Label;
use Vinnia\Shipping\Package;
use Vinnia\Shipping\Quote;
use Vinnia\Shipping\ServiceInterface;
use DateTimeImmutable;
use DateTimeZone;
use SimpleXMLElement;
use Vinnia\Shipping\Tracking;
use Vinnia\Shipping\TrackingActivity;
use Vinnia\Shipping\Xml;
use Vinnia\Util\Collection;
use Vinnia\Util\Measurement\Unit;

class Service implements ServiceInterface
{

    const URL_TEST = 'https://test';
    const URL_PRODUCTION = 'https://gateway.fedex.com/web-services';

    /**
     * @var ClientInterface
     */
    private $guzzle;

    /**
     * @var Credentials
     */
    private $credentials;

    /**
     * @var string
     */
    private $url;

    function __construct(ClientInterface $guzzle, Credentials $credentials, string $url = self::URL_PRODUCTION)
    {
        $this->guzzle = $guzzle;
        $this->credentials = $credentials;
        $this->url = $url;
    }

    /**
     * @param Address $address
     * @return array
     */
    private function addressToArray(Address $address): array
    {
        return [
            'StreetLines' => $address->getLines(),
            'City' => $address->getCity(),
            'StateOrProvinceCode' => $address->getState(),
            'PostalCode' => $address->getZip(),
            'CountryCode' => $address->getCountry(),
        ];
    }

    /**
     * @param Address $sender
     * @param Address $recipient
     * @param Package $package
     * @param array $options
     * @return PromiseInterface
     */
    public function getQuotes(Address $sender, Address $recipient, Package $package, array $options = []): PromiseInterface
    {
        $package = $package->convertTo(Unit::CENTIMETER, Unit::KILOGRAM);

        // after value conversions we might get lots of decimals. deal with that
        $length = number_format($package->getLength()->getValue(), 0, '.', '');
        $width = number_format($package->getWidth()->getValue(), 0, '.', '');
        $height = number_format($package->getHeight()->getValue(), 0, '.', '');
        $weight = number_format($package->getWeight()->getValue(), 0, '.', '');

        $rateRequest = Xml::fromArray([
            'RateRequest' => [
                'WebAuthenticationDetail' => [
                    'UserCredential' => [
                        'Key' => $this->credentials->getCredentialKey(),
                        'Password' => $this->credentials->getCredentialPassword(),
                    ],
                ],
                'ClientDetail' => [
                    'AccountNumber' => $this->credentials->getAccountNumber(),
                    'MeterNumber' => $this->credentials->getMeterNumber(),
                ],
                'Version' => [
                    'ServiceId' => 'crs',
                    'Major' => 20,
                    'Intermediate' => 0,
                    'Minor' => 0,
                ],
                'RequestedShipment' => [
                    'DropoffType' => 'REGULAR_PICKUP',
                    'PackagingType' => 'YOUR_PACKAGING',
                    'Shipper' => [
                        'Address' => $this->addressToArray($sender),
                    ],
                    'Recipient' => [
                        'Address' => $this->addressToArray($recipient),
                    ],
                    'ShippingChargesPayment' => [
                        'PaymentType' => 'SENDER',
                    ],
                    'RateRequestTypes' => 'NONE',
                    'PackageCount' => 1,
                    'RequestedPackageLineItems' => [
                        'SequenceNumber' => 1,
                        'GroupNumber' => 1,
                        'GroupPackageCount' => 1,
                        'Weight' => [
                            'Units' => 'KG',
                            'Value' => $weight,
                        ],
                        'Dimensions' => [
                            'Length' => $length,
                            'Width' => $width,
                            'Height' => $height,
                            'Units' => 'CM',
                        ],
                    ],
                ],
            ],
        ]);

        $body = <<<EOD
<p:Envelope xmlns:p="http://schemas.xmlsoap.org/soap/envelope/" xmlns="http://fedex.com/ws/rate/v20">
   <p:Body>{$rateRequest}</p:Body>
</p:Envelope>
EOD;

        return $this->guzzle->requestAsync('POST', $this->url . '/rate', [
            'headers' => [
                'Accept' => 'text/xml',
                'Content-Type' => 'text/xml',
            ],
            'body' => $body,
        ])->then(function (ResponseInterface $response) {
            $body = (string) $response->getBody();

            $xml = new SimpleXMLElement($body, LIBXML_PARSEHUGE);
            $details = $xml->xpath('/SOAP-ENV:Envelope/SOAP-ENV:Body/*[local-name()=\'RateReply\']/*[local-name()=\'RateReplyDetails\']');

            return array_map(function (SimpleXMLElement $element): Quote {
                $product = (string) $element->{'ServiceType'};

                $total = $element
                    ->{'RatedShipmentDetails'}
                    ->{'ShipmentRateDetail'}
                    ->{'TotalNetChargeWithDutiesAndTaxes'};

                $amountString = (string) $total->{'Amount'};
                $amount = (int) round(((float) $amountString) * pow(10, 2));

                return new Quote('FedEx', $product, new Money($amount, new Currency((string) $total->{'Currency'})));
            }, $details);
        });
    }

    /**
     * @param string $trackingNumber
     * @param array $options
     * @return PromiseInterface
     */
    public function getTrackingStatus(string $trackingNumber, array $options = []): PromiseInterface
    {
        $trackRequest = Xml::fromArray([
            'TrackRequest' => [
                'WebAuthenticationDetail' => [
                    'UserCredential' => [
                        'Key' => $this->credentials->getCredentialKey(),
                        'Password' => $this->credentials->getCredentialPassword(),
                    ],
                ],
                'ClientDetail' => [
                    'AccountNumber' => $this->credentials->getAccountNumber(),
                    'MeterNumber' => $this->credentials->getMeterNumber(),
                ],
                'Version' => [
                    'ServiceId' => 'trck',
                    'Major' => 12,
                    'Intermediate' => 0,
                    'Minor' => 0,
                ],
                'SelectionDetails' => [
                    'PackageIdentifier' => [
                        'Type' => 'TRACKING_NUMBER_OR_DOORTAG',
                        'Value' => $trackingNumber,
                    ],
                ],
                'ProcessingOptions' => 'INCLUDE_DETAILED_SCANS',
            ],
        ]);

        $body = <<<EOD
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns="http://fedex.com/ws/track/v12">
   <soapenv:Header />
   <soapenv:Body>{$trackRequest}</soapenv:Body>
</soapenv:Envelope>
EOD;

        return $this->guzzle->requestAsync('POST', $this->url . '/track', [
            'headers' => [
                'Accept' => 'text/xml',
                'Content-Type' => 'text/xml',
            ],
            'body' => $body,
        ])->then(function (ResponseInterface $response) {
            $body = (string) $response->getBody();

            $xml = new SimpleXMLElement($body, LIBXML_PARSEHUGE);

            $details = $xml->xpath('/SOAP-ENV:Envelope/SOAP-ENV:Body/*[local-name()=\'TrackReply\']/*[local-name()=\'CompletedTrackDetails\']/*[local-name()=\'TrackDetails\']');

            if (!$details) {
                return new RejectedPromise($body);
            }

            $service = (string) $details[0]->{'Service'}->{'Type'};
            $events = $details[0]->xpath('*[local-name()=\'Events\']');

            $activities = (new Collection($events))->map(function (SimpleXMLElement $element) {
                $status = $this->getStatusFromEventType((string) $element->{'EventType'});
                $description = (string) $element->{'EventDescription'};
                $dt = new DateTimeImmutable((string) $element->{'Timestamp'});
                $address = new Address(
                    '',
                    [],
                    (string) $element->{'Address'}->{'PostalCode'},
                    (string) $element->{'Address'}->{'City'},
                    (string) $element->{'Address'}->{'StateOrProvinceCode'},
                    (string) $element->{'Address'}->{'CountryName'}
                );

                return new TrackingActivity($status, $description, $dt, $address);
            })->value();

            return new Tracking('FedEx', $service, $activities);
        });
    }

    /**
     * @param string $type
     * @return int
     */
    private function getStatusFromEventType(string $type): int
    {
        $type = mb_strtoupper($type, 'utf-8');

        // status mappings stolen from keeptracker.
        $typeMap = [
            TrackingActivity::STATUS_DELIVERED => [
                'DL',
            ],
            TrackingActivity::STATUS_EXCEPTION => [
                // cancelled
                'CA',

                // general issues
                'CD', 'DY', 'DE', 'HL', 'CH', 'SE',

                // returned to shipper
                'RS',
            ],
        ];

        foreach ($typeMap as $status => $types) {
            if (in_array($type, $types)) {
                return $status;
            }
        }

        return TrackingActivity::STATUS_IN_TRANSIT;
    }

    /**
     * @param DateTimeInterface $date
     * @param Address $sender
     * @param Address $recipient
     * @param Package $package
     * @param array $options
     * @return PromiseInterface
     */
    public function createLabel(DateTimeInterface $date, Address $sender, Address $recipient, Package $package, array $options = []): PromiseInterface
    {
        $package = $package->convertTo(Unit::CENTIMETER, Unit::KILOGRAM);

        // after value conversions we might get lots of decimals. deal with that
        $length = number_format($package->getLength()->getValue(), 0, '.', '');
        $width = number_format($package->getWidth()->getValue(), 0, '.', '');
        $height = number_format($package->getHeight()->getValue(), 0, '.', '');
        $weight = number_format($package->getWeight()->getValue(), 0, '.', '');

        $shipRequest = Xml::fromArray([
            'ProcessShipmentRequest' => [
                'WebAuthenticationDetail' => [
                    'UserCredential' => [
                        'Key' => $this->credentials->getCredentialKey(),
                        'Password' => $this->credentials->getCredentialPassword(),
                    ],
                ],
                'ClientDetail' => [
                    'AccountNumber' => $this->credentials->getAccountNumber(),
                    'MeterNumber' => $this->credentials->getMeterNumber(),
                ],
                'Version' => [
                    'ServiceId' => 'ship',
                    'Major' => 19,
                    'Intermediate' => 0,
                    'Minor' => 0,
                ],
                'RequestedShipment' => [
                    'ShipTimestamp' => $date->format('c'),
                    'DropoffType' => 'REGULAR_PICKUP',
                    'ServiceType' => 'FEDEX_GROUND', // TODO: should be configurable
                    'PackagingType' => 'YOUR_PACKAGING',
                    'Shipper' => [
                        'Contact' => [
                            'CompanyName' => $sender->getName(),
                            'PhoneNumber' => '123456',
                        ],
                        'Address' => $this->addressToArray($sender),
                    ],
                    'Recipient' => [
                        'Contact' => [
                            'CompanyName' => $recipient->getName(),
                            'PhoneNumber' => '123456',
                        ],
                        'Address' => $this->addressToArray($recipient),
                    ],
                    'ShippingChargesPayment' => [
                        'PaymentType' => 'SENDER',
                        'Payor' => [
                            'ResponsibleParty' => [
                                'AccountNumber' => $this->credentials->getAccountNumber(),
                            ],
                        ],
                    ],
                    //'CustomsClearanceDetail' => [
                    //
                    //]
                    'LabelSpecification' => [
                        'LabelFormatType' => 'COMMON2D',
                        'ImageType' => 'PDF',
                        'LabelStockType' => 'PAPER_LETTER',
                    ],
                    'PackageCount' => 1,
                    'RequestedPackageLineItems' => [
                        'SequenceNumber' => 1,
                        'GroupNumber' => 1,
                        'GroupPackageCount' => 1,
                        'Weight' => [
                            'Units' => 'KG',
                            'Value' => $weight,
                        ],
                        'Dimensions' => [
                            'Length' => $length,
                            'Width' => $width,
                            'Height' => $height,
                            'Units' => 'CM',
                        ],
                    ],
                ],
            ],
        ]);
        $body = <<<EOD
<p:Envelope xmlns:p="http://schemas.xmlsoap.org/soap/envelope/" xmlns="http://fedex.com/ws/ship/v19">
   <p:Body>$shipRequest</p:Body>
</p:Envelope>
EOD;

        return $this->guzzle->requestAsync('POST', $this->url . '/ship', [
            'headers' => [
                'Accept' => 'text/xml',
                'Content-Type' => 'text/xml',
            ],
            'body' => $body,
        ])->then(function (ResponseInterface $response) {
            $body = (string) $response->getBody();

            echo $body;

            if (strpos($body, '<HighestSeverity>SUCCESS</HighestSeverity>') === false) {
                return new RejectedPromise($body);
            }

            preg_match('/<TrackingNumber>(.+)<\/TrackingNumber>/', $body, $matches);

            $trackingNumber = $matches[1];

            preg_match('/<Image>(.+)<\/Image>/', $body, $matches);

            $image = base64_decode($matches[1]);

            return new Label($trackingNumber, 'FedEx', 'PDF', $image);
        }, function (ServerException $e) {
           $body = (string) $e->getResponse()->getBody();
           echo $body;
        });
    }

    public function deleteLabel(string $id, string $type): PromiseInterface
    {
        $deleteRequest = Xml::fromArray([
            'DeleteShipmentRequest' => [
                'WebAuthenticationDetail' => [
                    'UserCredential' => [
                        'Key' => $this->credentials->getCredentialKey(),
                        'Password' => $this->credentials->getCredentialPassword(),
                    ],
                ],
                'ClientDetail' => [
                    'AccountNumber' => $this->credentials->getAccountNumber(),
                    'MeterNumber' => $this->credentials->getMeterNumber(),
                ],
                'Version' => [
                    'ServiceId' => 'ship',
                    'Major' => 19,
                    'Intermediate' => 0,
                    'Minor' => 0,
                ],
                'TrackingId' => [
                    'TrackingIdType' => $type,
                    'TrackingNumber' => $id,
                ],
                'DeletionControl' => 'DELETE_ALL_PACKAGES',
            ],
        ]);

        $body = <<<EOD
<p:Envelope xmlns:p="http://schemas.xmlsoap.org/soap/envelope/" xmlns="http://fedex.com/ws/ship/v19">
   <p:Body>$deleteRequest</p:Body>
</p:Envelope>
EOD;
        //echo $body;
        return $this->guzzle->requestAsync('POST', $this->url . '/ship', [
            'headers' => [
                'Accept' => 'text/xml',
                'Content-Type' => 'text/xml',
            ],
            'body' => $body,
        ])->then(function (ResponseInterface $response) {
            $body = (string) $response->getBody();

            echo $body;

            return $body;
        });
    }

}
