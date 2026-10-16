<?php

namespace Modules\EcclesiasticalData\Support;

enum BishopUpdateRequestType: string
{
    case CreateBishop = 'create_bishop';
    case UpdateBishop = 'update_bishop';
    case ChangeCurrentBishop = 'change_current_bishop';
    case AddAuxiliary = 'add_auxiliary';
    case AddCoadjutor = 'add_coadjutor';
    case UpdateAppointment = 'update_appointment';
    case UpdateImage = 'update_image';
    case CorrectInformation = 'correct_information';

    public function isOrdinarySuggestion(): bool
    {
        return $this === self::ChangeCurrentBishop || $this === self::CreateBishop;
    }

    public function usesExistingBishopTarget(): bool
    {
        return in_array($this, [
            self::UpdateBishop,
            self::UpdateImage,
            self::CorrectInformation,
            self::UpdateAppointment,
            self::AddAuxiliary,
            self::AddCoadjutor,
        ], true);
    }
}
