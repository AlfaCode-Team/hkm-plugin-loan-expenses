<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses\Domain;

/**
 * The closed set of expense categories.
 *
 * A closed enum, not a free string: a category typed wrongly ("Boda " or
 * "transport") would otherwise become a second row in every report it touches.
 * Adding a category is a code change here, on purpose. Existing rows keep the
 * string they were stored with; only WRITES are checked against this set.
 */
enum ExpenseCategory: string
{
    // Road
    case BODA_FARE = 'boda_fare';
    case TAXI_FARE = 'taxi_fare';
    case FUEL = 'fuel';
    case VEHICLE_MAINTENANCE = 'vehicle_maintenance';
    case PARKING = 'parking';
    case ROAD_TOLL = 'road_toll';

    // People
    case STAFF_LUNCH = 'staff_lunch';
    case STAFF_ALLOWANCE = 'staff_allowance';
    case TRAINING = 'training';
    case MEDICAL = 'medical';
    case WELFARE = 'welfare';

    // Office
    case RENT = 'rent';
    case UTILITIES_ELECTRICITY = 'utilities_electricity';
    case UTILITIES_WATER = 'utilities_water';
    case AIRTIME = 'airtime';
    case INTERNET = 'internet';
    case STATIONERY = 'stationery';
    case PRINTING = 'printing';
    case CLEANING = 'cleaning';
    case REPAIRS = 'repairs';
    case SECURITY = 'security';

    // Money
    case BANK_CHARGES = 'bank_charges';
    case MOBILE_MONEY_CHARGES = 'mobile_money_charges';
    case INTEREST_PAID = 'interest_paid';

    // The law
    case LICENCE_AND_PERMITS = 'licence_and_permits';
    case TAXES_AND_LEVIES = 'taxes_and_levies';
    case LEGAL_FEES = 'legal_fees';
    case OTHER = 'other';

    public function group(): string
    {
        return match ($this) {
            self::BODA_FARE, self::TAXI_FARE, self::FUEL, self::VEHICLE_MAINTENANCE,
            self::PARKING, self::ROAD_TOLL => 'road',

            self::STAFF_LUNCH, self::STAFF_ALLOWANCE, self::TRAINING,
            self::MEDICAL, self::WELFARE => 'people',

            self::RENT, self::UTILITIES_ELECTRICITY, self::UTILITIES_WATER, self::AIRTIME,
            self::INTERNET, self::STATIONERY, self::PRINTING, self::CLEANING,
            self::REPAIRS, self::SECURITY => 'office',

            self::BANK_CHARGES, self::MOBILE_MONEY_CHARGES, self::INTEREST_PAID => 'money',

            self::LICENCE_AND_PERMITS, self::TAXES_AND_LEVIES, self::LEGAL_FEES, self::OTHER => 'law',
        };
    }
}
