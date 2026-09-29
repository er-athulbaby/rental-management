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
}
