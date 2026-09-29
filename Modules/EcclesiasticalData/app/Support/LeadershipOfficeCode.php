<?php

namespace Modules\EcclesiasticalData\Support;

enum LeadershipOfficeCode: string
{
    case Pope = 'pope';
    case DiocesanBishop = 'diocesan_bishop';
    case AuxiliaryBishop = 'auxiliary_bishop';
    case CoadjutorBishop = 'coadjutor_bishop';
    case ParishPriest = 'parish_priest';
    case AssociateParishPriest = 'associate_parish_priest';
    case ParishAdministrator = 'parish_administrator';
}
