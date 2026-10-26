<?php

namespace App\Common\Domain;

enum MovementType: string
{
    case Receipt = 'receipt';
    case Relocation = 'relocation';
    case TransferShipment = 'transfer_shipment';
    case TransferReceipt = 'transfer_receipt';
    case Reservation = 'reservation';
    case Release = 'release';
    case ProductionIssue = 'production_issue';
    case Return = 'return';
    case SaleDispatch = 'sale_dispatch';
    case Loss = 'loss';
    case AdjustmentIncrease = 'adjustment_increase';
    case AdjustmentDecrease = 'adjustment_decrease';
    case QualityHold = 'quality_hold';
    case QualityRelease = 'quality_release';
    case QualityReject = 'quality_reject';
    case Reversal = 'reversal';
}
