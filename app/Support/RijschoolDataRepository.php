<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class RijschoolDataRepository
{
    private const SESSION_KEY = 'rijschool_data';
    private const PER_PAGE = 4;

    public function paginateInstructeurs(int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->paginateCollection(
            collect($this->data()['instructeurs'])
            ->map(fn (array $instructeur): object => $this->enrichInstructeur($instructeur))
            ->sortByDesc('AantalSterren')
                ->values(),
            $perPage,
            'page'
        );
    }

    public function getInstructeur(int $instructeurId): ?object
    {
        $instructeur = Arr::first($this->data()['instructeurs'], fn (array $item): bool => (int) $item['Id'] === $instructeurId);

        return $instructeur ? $this->enrichInstructeur($instructeur) : null;
    }

    public function getTypeVoertuigen(): Collection
    {
        return collect($this->data()['type_voertuigen'])
            ->map(fn (array $typeVoertuig): object => (object) $typeVoertuig)
            ->values();
    }

    public function getVoertuig(int $voertuigId): ?object
    {
        $voertuig = Arr::first($this->data()['voertuigen'], fn (array $item): bool => (int) $item['Id'] === $voertuigId);

        return $voertuig ? $this->enrichVoertuig($voertuig) : null;
    }

    public function paginateVoertuigenVanInstructeur(int $instructeurId, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->paginateCollection(
            collect($this->data()['voertuigen'])
            ->filter(fn (array $voertuig): bool => $this->voertuigHeeftInstructeur($voertuig['Id'], $instructeurId))
            ->map(fn (array $voertuig): object => $this->enrichVoertuig($voertuig))
            ->sortBy(fn (object $voertuig): string => $voertuig->Rijbewijscategorie . ' ' . $voertuig->Type)
                ->values(),
            $perPage,
            'page'
        );
    }

    public function paginateBeschikbareVoertuigen(int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->paginateCollection(
            collect($this->data()['voertuigen'])
            ->filter(fn (array $voertuig): bool => $this->voertuigInstructeurId($voertuig['Id']) === null)
            ->map(fn (array $voertuig): object => $this->enrichVoertuig($voertuig))
            ->sortBy(fn (object $voertuig): string => $voertuig->Rijbewijscategorie . ' ' . $voertuig->Type)
                ->values(),
            $perPage,
            'page'
        );
    }

    public function assignVoertuigToInstructeur(int $voertuigId, int $instructeurId): ?object
    {
        $data = $this->data();
        $voertuigIndex = $this->findIndex($data['voertuigen'], $voertuigId);

        if ($voertuigIndex === null) {
            return null;
        }

        $assignmentIndex = $this->findAssignmentIndexByVoertuigId($data['voertuig_instructeurs'], $voertuigId);
        $now = $this->now();

        if ($assignmentIndex === null) {
            $data['voertuig_instructeurs'][] = [
                'Id' => $this->nextId($data['voertuig_instructeurs']),
                'VoertuigId' => $voertuigId,
                'InstructeurId' => $instructeurId,
                'DatumToekenning' => $now->format('Y-m-d'),
                'IsActief' => 1,
                'Opmerking' => null,
                'DatumAangemaakt' => $now->format('Y-m-d H:i:s.u'),
                'DatumGewijzigd' => $now->format('Y-m-d H:i:s.u'),
            ];
        } else {
            $data['voertuig_instructeurs'][$assignmentIndex]['InstructeurId'] = $instructeurId;
            $data['voertuig_instructeurs'][$assignmentIndex]['DatumGewijzigd'] = $now->format('Y-m-d H:i:s.u');
        }

        $this->save($data);

        return $this->getVoertuig($voertuigId);
    }

    public function updateVoertuig(int $voertuigId, array $payload): ?object
    {
        $data = $this->data();
        $voertuigIndex = $this->findIndex($data['voertuigen'], $voertuigId);

        if ($voertuigIndex === null) {
            return null;
        }

        $originalInstructeurId = $this->voertuigInstructeurId($voertuigId);
        $selectedInstructeurId = array_key_exists('InstructeurId', $payload) && $payload['InstructeurId'] !== null && $payload['InstructeurId'] !== ''
            ? (int) $payload['InstructeurId']
            : null;

        if ($selectedInstructeurId === null && $originalInstructeurId !== null) {
            $selectedInstructeurId = $originalInstructeurId;
        }

        $now = $this->now();

        $data['voertuigen'][$voertuigIndex]['TypeVoertuigId'] = (int) $payload['TypeVoertuigId'];
        $data['voertuigen'][$voertuigIndex]['Type'] = (string) $payload['Type'];
        $data['voertuigen'][$voertuigIndex]['Kenteken'] = (string) $payload['Kenteken'];
        $data['voertuigen'][$voertuigIndex]['Brandstof'] = (string) $payload['Brandstof'];
        $data['voertuigen'][$voertuigIndex]['DatumGewijzigd'] = $now->format('Y-m-d H:i:s.u');

        if ($selectedInstructeurId !== $originalInstructeurId) {
            $assignmentIndex = $this->findAssignmentIndexByVoertuigId($data['voertuig_instructeurs'], $voertuigId);

            if ($selectedInstructeurId === null) {
                if ($assignmentIndex !== null) {
                    unset($data['voertuig_instructeurs'][$assignmentIndex]);
                    $data['voertuig_instructeurs'] = array_values($data['voertuig_instructeurs']);
                }
            } elseif ($assignmentIndex === null) {
                $data['voertuig_instructeurs'][] = [
                    'Id' => $this->nextId($data['voertuig_instructeurs']),
                    'VoertuigId' => $voertuigId,
                    'InstructeurId' => $selectedInstructeurId,
                    'DatumToekenning' => $now->format('Y-m-d'),
                    'IsActief' => 1,
                    'Opmerking' => null,
                    'DatumAangemaakt' => $now->format('Y-m-d H:i:s.u'),
                    'DatumGewijzigd' => $now->format('Y-m-d H:i:s.u'),
                ];
            } else {
                $data['voertuig_instructeurs'][$assignmentIndex]['InstructeurId'] = $selectedInstructeurId;
                $data['voertuig_instructeurs'][$assignmentIndex]['DatumGewijzigd'] = $now->format('Y-m-d H:i:s.u');
            }
        }

        $this->save($data);

        return $this->getVoertuig($voertuigId);
    }

    public function getOriginalInstructeurId(int $voertuigId): ?int
    {
        return $this->voertuigInstructeurId($voertuigId);
    }

    private function data(): array
    {
        if (! session()->has(self::SESSION_KEY)) {
            session()->put(self::SESSION_KEY, $this->seedData());
        }

        return session()->get(self::SESSION_KEY);
    }

    private function save(array $data): void
    {
        session()->put(self::SESSION_KEY, $data);
    }

    private function seedData(): array
    {
        $now = $this->now()->format('Y-m-d H:i:s.u');

        return [
            'type_voertuigen' => [
                ['Id' => 1, 'TypeVoertuig' => 'Personenauto', 'Rijbewijscategorie' => 'B', 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 2, 'TypeVoertuig' => 'Vrachtwagen', 'Rijbewijscategorie' => 'C', 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 3, 'TypeVoertuig' => 'Bus', 'Rijbewijscategorie' => 'D', 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 4, 'TypeVoertuig' => 'Bromfiets', 'Rijbewijscategorie' => 'AM', 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
            ],
            'instructeurs' => [
                ['Id' => 1, 'Voornaam' => 'Li', 'Tussenvoegsel' => '', 'Achternaam' => 'Zhan', 'Mobiel' => '06-28493827', 'DatumInDienst' => '2015-04-17', 'AantalSterren' => 3, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 2, 'Voornaam' => 'Leroy', 'Tussenvoegsel' => '', 'Achternaam' => 'Boerhaven', 'Mobiel' => '06-39398734', 'DatumInDienst' => '2018-06-25', 'AantalSterren' => 1, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 3, 'Voornaam' => 'Yoeri', 'Tussenvoegsel' => 'Van', 'Achternaam' => 'Veen', 'Mobiel' => '06-24383291', 'DatumInDienst' => '2010-05-12', 'AantalSterren' => 3, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 4, 'Voornaam' => 'Bert', 'Tussenvoegsel' => 'Van', 'Achternaam' => 'Sali', 'Mobiel' => '06-48293823', 'DatumInDienst' => '2023-01-10', 'AantalSterren' => 4, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 5, 'Voornaam' => 'Mohammed', 'Tussenvoegsel' => 'El', 'Achternaam' => 'Yassidi', 'Mobiel' => '06-34291234', 'DatumInDienst' => '2010-06-14', 'AantalSterren' => 5, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
            ],
            'voertuigen' => [
                ['Id' => 1, 'Kenteken' => 'AU-67-IO', 'Type' => 'Golf', 'Bouwjaar' => '2017-06-12', 'Brandstof' => 'Diesel', 'TypeVoertuigId' => 1, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 2, 'Kenteken' => 'TR-24-OP', 'Type' => 'DAF', 'Bouwjaar' => '2019-05-23', 'Brandstof' => 'Diesel', 'TypeVoertuigId' => 2, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 3, 'Kenteken' => 'TH-78-KL', 'Type' => 'Mercedes', 'Bouwjaar' => '2023-01-01', 'Brandstof' => 'Benzine', 'TypeVoertuigId' => 1, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 4, 'Kenteken' => '90-KL-TR', 'Type' => 'Fiat 500', 'Bouwjaar' => '2021-09-12', 'Brandstof' => 'Benzine', 'TypeVoertuigId' => 1, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 5, 'Kenteken' => '34-TK-LP', 'Type' => 'Scania', 'Bouwjaar' => '2015-03-13', 'Brandstof' => 'Diesel', 'TypeVoertuigId' => 2, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 6, 'Kenteken' => 'YY-OP-78', 'Type' => 'BMW M5', 'Bouwjaar' => '2022-05-13', 'Brandstof' => 'Diesel', 'TypeVoertuigId' => 1, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 7, 'Kenteken' => 'UU-HH-JK', 'Type' => 'M.A.N', 'Bouwjaar' => '2017-12-03', 'Brandstof' => 'Diesel', 'TypeVoertuigId' => 2, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 8, 'Kenteken' => 'ST-FZ-28', 'Type' => 'Citroën', 'Bouwjaar' => '2018-01-20', 'Brandstof' => 'Elektrisch', 'TypeVoertuigId' => 1, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 9, 'Kenteken' => '123-FR-T', 'Type' => 'Piaggio ZIP', 'Bouwjaar' => '2021-02-01', 'Brandstof' => 'Benzine', 'TypeVoertuigId' => 4, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 10, 'Kenteken' => 'DRS-52-P', 'Type' => 'Vespa', 'Bouwjaar' => '2022-03-21', 'Brandstof' => 'Benzine', 'TypeVoertuigId' => 4, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 11, 'Kenteken' => 'STP-12-U', 'Type' => 'Kymco', 'Bouwjaar' => '2022-07-02', 'Brandstof' => 'Benzine', 'TypeVoertuigId' => 4, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 12, 'Kenteken' => '45-SD-23', 'Type' => 'Renault', 'Bouwjaar' => '2023-01-01', 'Brandstof' => 'Diesel', 'TypeVoertuigId' => 3, 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
            ],
            'voertuig_instructeurs' => [
                ['Id' => 1, 'VoertuigId' => 1, 'InstructeurId' => 5, 'DatumToekenning' => '2017-06-18', 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 2, 'VoertuigId' => 3, 'InstructeurId' => 1, 'DatumToekenning' => '2021-09-26', 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 3, 'VoertuigId' => 9, 'InstructeurId' => 1, 'DatumToekenning' => '2021-09-27', 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 4, 'VoertuigId' => 4, 'InstructeurId' => 4, 'DatumToekenning' => '2022-08-01', 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 5, 'VoertuigId' => 5, 'InstructeurId' => 1, 'DatumToekenning' => '2019-08-30', 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
                ['Id' => 6, 'VoertuigId' => 10, 'InstructeurId' => 5, 'DatumToekenning' => '2020-02-02', 'IsActief' => 1, 'Opmerking' => null, 'DatumAangemaakt' => $now, 'DatumGewijzigd' => $now],
            ],
        ];
    }

    private function enrichInstructeur(array $instructeur): object
    {
        $instructeur['VoertuigenCount'] = collect($this->data()['voertuig_instructeurs'])
            ->where('InstructeurId', (int) $instructeur['Id'])
            ->count();

        $instructeur['Naam'] = $this->formatNaam($instructeur['Voornaam'], $instructeur['Tussenvoegsel'], $instructeur['Achternaam']);

        return (object) $instructeur;
    }

    private function enrichVoertuig(array $voertuig): object
    {
        $typeVoertuig = collect($this->data()['type_voertuigen'])
            ->firstWhere('Id', (int) $voertuig['TypeVoertuigId']);

        $instructeurId = $this->voertuigInstructeurId((int) $voertuig['Id']);

        $voertuig['TypeVoertuig'] = $typeVoertuig['TypeVoertuig'] ?? null;
        $voertuig['Rijbewijscategorie'] = $typeVoertuig['Rijbewijscategorie'] ?? null;
        $voertuig['InstructeurId'] = $instructeurId;
        $voertuig['InstructeurNaam'] = $instructeurId ? $this->instructeurNaamById($instructeurId) : null;

        return (object) $voertuig;
    }

    private function voertuigHeeftInstructeur(int $voertuigId, int $instructeurId): bool
    {
        return $this->voertuigInstructeurId($voertuigId) === $instructeurId;
    }

    private function voertuigInstructeurId(int $voertuigId): ?int
    {
        $assignment = Arr::first($this->data()['voertuig_instructeurs'], fn (array $item): bool => (int) $item['VoertuigId'] === $voertuigId);

        return $assignment ? (int) $assignment['InstructeurId'] : null;
    }

    private function findIndex(array $items, int $id): ?int
    {
        foreach ($items as $index => $item) {
            if ((int) $item['Id'] === $id) {
                return $index;
            }
        }

        return null;
    }

    private function findAssignmentIndexByVoertuigId(array $assignments, int $voertuigId): ?int
    {
        foreach ($assignments as $index => $assignment) {
            if ((int) $assignment['VoertuigId'] === $voertuigId) {
                return $index;
            }
        }

        return null;
    }

    private function nextId(array $items): int
    {
        return collect($items)->max('Id') + 1;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    private function instructeurNaamById(int $instructeurId): ?string
    {
        $instructeur = Arr::first($this->data()['instructeurs'], fn (array $item): bool => (int) $item['Id'] === $instructeurId);

        return $instructeur
            ? $this->formatNaam($instructeur['Voornaam'], $instructeur['Tussenvoegsel'], $instructeur['Achternaam'])
            : null;
    }

    private function formatNaam(string $voornaam, ?string $tussenvoegsel, string $achternaam): string
    {
        return trim(implode(' ', array_filter([$voornaam, $tussenvoegsel, $achternaam], fn (?string $part): bool => filled($part))));
    }

    private function paginateCollection(Collection $items, int $perPage, string $pageName): LengthAwarePaginator
    {
        $currentPage = LengthAwarePaginator::resolveCurrentPage($pageName);
        $total = $items->count();
        $results = $items->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }
}
