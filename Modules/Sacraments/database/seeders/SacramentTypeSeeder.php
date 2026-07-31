<?php

namespace Modules\Sacraments\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Seed the Seven Sacraments of the Catholic Church
 */
class SacramentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $sacraments = [
            // SACRAMENTS OF INITIATION
            [
                'name' => 'Baptism',
                'code' => 'BAPTISM',
                'category' => 'initiation',
                'description' => 'The first sacrament of Christian initiation, washing away original sin.',
                'theological_significance' => 'Baptism incorporates us into Christ and forms us into God\'s people. It frees us from sin and makes us children of God.',
                'display_order' => 1,
                'min_age_years' => 0,
                'typical_age_years' => 0,
                'repeatable' => false,
                'requires_minister' => true,
                'minister_type' => 'priest',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Confirmation',
                'code' => 'CONFIRMATION',
                'category' => 'initiation',
                'description' => 'Sacrament that seals and confirms the grace of Baptism through the Holy Spirit.',
                'theological_significance' => 'Confirmation perfects Baptismal grace; it is the sacrament which gives the Holy Spirit in order to root us more deeply in the divine filiation.',
                'display_order' => 2,
                'min_age_years' => 7,
                'typical_age_years' => 14,
                'repeatable' => false,
                'requires_minister' => true,
                'minister_type' => 'bishop',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Eucharist (First Holy Communion)',
                'code' => 'EUCHARIST',
                'category' => 'initiation',
                'description' => 'Reception of the Body and Blood of Christ.',
                'theological_significance' => 'The Eucharist is the source and summit of the Christian life. The other sacraments, and indeed all ecclesiastical ministries and works of the apostolate, are bound up with the Eucharist and are oriented toward it.',
                'display_order' => 3,
                'min_age_years' => 7,
                'typical_age_years' => 8,
                'repeatable' => true,
                'requires_minister' => true,
                'minister_type' => 'priest',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // SACRAMENTS OF HEALING
            [
                'name' => 'Reconciliation (Confession)',
                'code' => 'RECONCILIATION',
                'category' => 'healing',
                'description' => 'Sacrament of Penance, confession of sins to receive God\'s forgiveness.',
                'theological_significance' => 'Through the sacrament of Reconciliation, the baptized obtain pardon from God for the offenses committed against him and are reconciled with the Church.',
                'display_order' => 4,
                'min_age_years' => 7,
                'typical_age_years' => 8,
                'repeatable' => true,
                'requires_minister' => true,
                'minister_type' => 'priest',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Anointing of the Sick',
                'code' => 'ANOINTING',
                'category' => 'healing',
                'description' => 'Sacrament for the seriously ill or elderly to receive special grace.',
                'theological_significance' => 'By the sacred anointing and the prayer of the priests, the whole Church commends those who are sick to the suffering and glorified Lord.',
                'display_order' => 5,
                'min_age_years' => null,
                'typical_age_years' => null,
                'repeatable' => true,
                'requires_minister' => true,
                'minister_type' => 'priest',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            
            // SACRAMENTS OF SERVICE
            [
                'name' => 'Holy Orders',
                'code' => 'HOLY_ORDERS',
                'category' => 'service',
                'description' => 'Ordination to the diaconate, priesthood, or episcopate.',
                'theological_significance' => 'The sacrament of Holy Orders consecrates and deputes some among the Christian faithful to shepherd the faithful as ministers by the word and grace of God.',
                'display_order' => 6,
                'min_age_years' => 25,
                'typical_age_years' => 28,
                'repeatable' => false,
                'requires_minister' => true,
                'minister_type' => 'bishop',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Matrimony (Marriage)',
                'code' => 'MATRIMONY',
                'category' => 'service',
                'description' => 'The covenant by which a man and woman establish a lifelong partnership.',
                'theological_significance' => 'The matrimonial covenant establishes a partnership of the whole of life and is ordered by its nature to the good of the spouses and the procreation and education of offspring.',
                'display_order' => 7,
                'min_age_years' => 18,
                'typical_age_years' => 25,
                'repeatable' => false,
                'requires_minister' => true,
                'minister_type' => 'priest',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('sacrament_types')->insert($sacraments);

        $this->command->info('✅ Successfully seeded 7 sacrament types');
    }
}


