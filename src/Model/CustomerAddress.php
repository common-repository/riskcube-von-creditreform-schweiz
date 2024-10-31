<?php

namespace Cube\Model;

class CustomerAddress
{
    /** @var string|null $type Business|Consumer */
    public ?string $type = null;
    public ?string $businessName = null;
    public ?string $lastname = null;
    public ?string $firstname = null;
    /** @var string|null $co Empty String */
    public ?string $co = null;
    public ?string $street = null;
    /** @var string|null $houseNumber Empty String */
    public ?string $houseNumber = null;
    /** @var string|null $locationName City */
    public ?string $locationName = null;
    public ?string $state = null;
    public ?string $postCode = null;
    public ?string $country = null;
    public ?string $email = null;
    public ?string $phone = null;
    /** @var string|null $dateOfBirth Empty */
    public ?string $dateOfBirth = null;

    public function isSameAs(CustomerAddress $addressToCheck): bool
    {
        return ((array)$this) === ((array)$addressToCheck);
    }
}