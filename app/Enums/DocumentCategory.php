<?php

namespace App\Enums;

enum DocumentCategory: string
{
    case Photo = 'photo';
    case IdCopy = 'id_copy';
    case CrCopy = 'cr_copy';
    case SignedContract = 'signed_contract';
    case ChequeImage = 'cheque_image';
    case MoveOutPhoto = 'move_out_photo';
    case OwnerApproval = 'owner_approval';
    case GeneratedPdf = 'generated_pdf';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Photo => __('Photo'),
            self::IdCopy => __('ID copy'),
            self::CrCopy => __('CR copy'),
            self::SignedContract => __('Signed contract'),
            self::ChequeImage => __('Cheque image'),
            self::MoveOutPhoto => __('Move-out photo'),
            self::OwnerApproval => __('Owner approval'),
            self::GeneratedPdf => __('Generated PDF'),
            self::Other => __('Other'),
        };
    }
}
