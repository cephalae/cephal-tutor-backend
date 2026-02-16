<?php

namespace App\Console\Commands;

use App\Models\RecordCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class FixRecordCategoryNamesByPattern extends Command
{
    protected $signature = 'record-categories:fix-names-regex
                            {--dry-run : Show changes without saving}
                            {--update-slugs : Also update slugs to canonical slugs}
                            {--force : If multiple matches, pick the best match automatically}';

    protected $description = 'Fix truncated/incorrect RecordCategory names using regex + slug matching';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updateSlugs = (bool) $this->option('update-slugs');
        $force = (bool) $this->option('force');

        // ✅ Canonical category list + matching patterns (name + slug)
        $canon = $this->canonicalMatchers();

        $cats = RecordCategory::query()->get();

        $rows = [];
        $updated = 0;
        $skipped = 0;
        $ambiguous = 0;

        foreach ($cats as $cat) {
            $origName = (string) $cat->name;
            $origSlug = (string) $cat->slug;

            $nameN = $this->normalize($origName);
            $slugN = strtolower(trim($origSlug));

            $matches = [];

            foreach ($canon as $key => $def) {
                if ($this->matches($nameN, $slugN, $def)) {
                    $score = $this->scoreMatch($nameN, $slugN, $def);
                    $matches[] = [
                        'key' => $key,
                        'score' => $score,
                        'name' => $def['name'],
                        'slug' => $def['slug'],
                    ];
                }
            }

            if (count($matches) === 0) {
                $rows[] = [$cat->id, $origName, '(no match)', $origSlug, '', 'SKIP'];
                $skipped++;
                continue;
            }

            usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

            // If multiple close matches, treat as ambiguous unless --force
            if (count($matches) > 1 && !$force) {
                $top = $matches[0];
                $second = $matches[1];

                // If the scores are too close, skip to avoid wrong update
                if (($top['score'] - $second['score']) < 15) {
                    $rows[] = [
                        $cat->id,
                        $origName,
                        "AMBIGUOUS: {$top['name']} | {$second['name']}",
                        $origSlug,
                        '',
                        'AMBIGUOUS-SKIP'
                    ];
                    $ambiguous++;
                    continue;
                }
            }

            $best = $matches[0];

            $newName = $best['name'];
            $newSlug = $best['slug'];

            $willChangeName = $this->normalize($origName) !== $this->normalize($newName);
            $willChangeSlug = $updateSlugs && ($origSlug !== $newSlug);

            if (!$willChangeName && !$willChangeSlug) {
                $rows[] = [$cat->id, $origName, $newName, $origSlug, $updateSlugs ? $newSlug : '', 'OK'];
                continue;
            }

            if (!$dryRun) {
                $cat->name = $newName;

                if ($updateSlugs) {
                    // Ensure uniqueness if your DB has a unique index on slug
                    $slugToUse = $newSlug;
                    if (RecordCategory::where('slug', $slugToUse)->where('id', '!=', $cat->id)->exists()) {
                        // fallback: keep current slug if collision
                        $slugToUse = $origSlug ?: Str::slug($newName);
                    }
                    $cat->slug = $slugToUse;
                }

                $cat->save();
            }

            $rows[] = [
                $cat->id,
                $origName,
                $newName,
                $origSlug,
                $updateSlugs ? $newSlug : '',
                $dryRun ? 'WOULD UPDATE' : 'UPDATED'
            ];

            $updated++;
        }

        $this->table(
            ['ID', 'Old Name', 'New Name', 'Old Slug', $updateSlugs ? 'New Slug' : '(slug unchanged)', 'Status'],
            $rows
        );

        $this->info("Done. Updated: {$updated}. Skipped: {$skipped}. Ambiguous skipped: {$ambiguous}.");

        if ($dryRun) $this->warn('Dry-run enabled: no changes were saved.');

        return self::SUCCESS;
    }

    private function canonicalMatchers(): array
    {
        return [
            'infectious' => [
                'name' => 'Certain infectious and parasitic diseases',
                'slug' => 'infectious',
                'slug_hints' => ['infectious', 'parasitic'],
                'patterns' => [
                    '/\bcertain\b.*\binfectious\b.*\bparasitic\b/i',
                    '/\binfectious\b.*\bparasitic\b/i',
                ],
            ],
            'neoplasms' => [
                'name' => 'Neoplasms',
                'slug' => 'neoplasms',
                'slug_hints' => ['neoplasms', 'neoplasm', 'tumou', 'tumor', 'cancer'],
                'patterns' => [
                    '/\bneoplasms?\b/i',
                ],
            ],
            'blood_immune' => [
                'name' => 'Diseases of the blood and blood-forming organs and certain disorders involving the immune mechanism',
                'slug' => 'blood-and-immune',
                'slug_hints' => ['blood', 'immune', 'bloo'],
                'patterns' => [
                    '/\bdiseases\b.*\bblood\b.*\bblood[- ]forming\b.*\bimmune\b/i',
                    '/\bblood\b.*\bblood[- ]forming\b/i',
                    '/\bimmune mechanism\b/i',
                ],
            ],
            'endocrine' => [
                'name' => 'Endocrine, nutritional and metabolic diseases',
                'slug' => 'endocrine',
                'slug_hints' => ['endocrine', 'nutritional', 'metabolic', 'met'],
                'patterns' => [
                    '/\bendocrine\b.*\bnutritional\b.*\bmetabolic\b/i',
                    '/\bendocrine\b.*\bmetabolic\b/i',
                ],
            ],
            'mental' => [
                'name' => 'Mental and behavioural disorders',
                'slug' => 'mental',
                'slug_hints' => ['mental', 'behaviour', 'behavior'],
                'patterns' => [
                    '/\bmental\b.*\bbehavio(u)?ral\b/i',
                ],
            ],
            'nervous' => [
                'name' => 'Diseases of Nervous system',
                'slug' => 'nervous',
                'slug_hints' => ['nervous', 'nervous-system'],
                'patterns' => [
                    '/\bdiseases\b.*\bnervous\b/i',
                ],
            ],
            'eye' => [
                'name' => 'Diseases of Eye and adnexa',
                'slug' => 'eye',
                'slug_hints' => ['eye', 'adnexa'],
                'patterns' => [
                    '/\beye\b.*\badnexa\b/i',
                ],
            ],
            'ear' => [
                'name' => 'Diseases of Ear and Mastoid process',
                'slug' => 'ear',
                'slug_hints' => ['ear', 'mastoid'],
                'patterns' => [
                    '/\bear\b.*\bmastoid\b/i',
                ],
            ],
            'circulatory' => [
                'name' => 'Diseases of Circulatory system',
                'slug' => 'circulatory',
                'slug_hints' => ['circulatory', 'cardio'],
                'patterns' => [
                    '/\bcirculatory\b/i',
                    '/\bdiseases\b.*\bcirculatory\b/i',
                ],
            ],
            'respiratory' => [
                'name' => 'Diseases of Respiratory system',
                'slug' => 'respiratory',
                'slug_hints' => ['respiratory'],
                'patterns' => [
                    '/\brespiratory\b/i',
                ],
            ],
            'digestive' => [
                'name' => 'Diseases of Digestive system',
                'slug' => 'digestive',
                'slug_hints' => ['digestive'],
                'patterns' => [
                    '/\bdigestive\b/i',
                ],
            ],
            'skin' => [
                'name' => 'Diseases of Skin and subcutaneous tissue',
                'slug' => 'skin',
                'slug_hints' => ['skin', 'subcutaneous', 'subcutane'],
                'patterns' => [
                    '/\bskin\b.*\bsubcutaneous\b/i',
                    '/\bskin\b.*\bsubcutane/i',
                ],
            ],
            'musculoskeletal' => [
                'name' => 'Diseases of Musculoskeletal system and connective tissue',
                'slug' => 'musculoskeletal',
                'slug_hints' => ['musculoskeletal', 'connective', 'musculo'],
                'patterns' => [
                    '/\bmusculoskeletal\b.*\bconnective\b/i',
                    '/\bmusculoskeletal\b/i',
                ],
            ],
            'genitourinary' => [
                'name' => 'Diseases of Genitourinary system',
                'slug' => 'genitourinary',
                'slug_hints' => ['genitourinary'],
                'patterns' => [
                    '/\bgenitourinary\b/i',
                ],
            ],
            'pregnancy' => [
                'name' => 'Pregnancy, childbirth and the puerperium',
                'slug' => 'pregnancy',
                'slug_hints' => ['pregnancy', 'childbirth', 'puerperium'],
                'patterns' => [
                    '/\bpregnancy\b.*\bchildbirth\b.*\bpuerperium\b/i',
                    '/\bpuerperium\b/i',
                ],
            ],
            'perinatal' => [
                'name' => 'Certain conditions originating in the perinatal period',
                'slug' => 'perinatal',
                'slug_hints' => ['perinatal', 'originating'],
                'patterns' => [
                    '/\bperinatal\b/i',
                    '/\boriginating\b.*\bperinatal\b/i',
                ],
            ],
            'congenital' => [
                'name' => 'Congenital malformations, deformations and chromosomal abnormalities',
                'slug' => 'congenital',
                'slug_hints' => ['congenital', 'chromosomal'],
                'patterns' => [
                    '/\bcongenital\b.*\bchromosomal\b/i',
                    '/\bcongenital\b.*\bmalformations\b/i',
                ],
            ],
            'symptoms' => [
                'name' => 'Symptoms, signs and abnormal clinical and laboratory findings, not elsewhere classified',
                'slug' => 'symptoms-signs',
                'slug_hints' => ['symptoms', 'signs', 'abnormal', 'laboratory'],
                'patterns' => [
                    '/\bsymptoms\b.*\bsigns\b.*\babnormal\b/i',
                    '/\bnot\s+elsewhere\s+classified\b/i',
                ],
            ],
            'injury' => [
                'name' => 'Injury, poisoning and certain other consequences of external causes',
                'slug' => 'injury-poisoning',
                'slug_hints' => ['injury', 'poisoning', 'external-causes'],
                'patterns' => [
                    '/\binjury\b.*\bpoisoning\b.*\bexternal causes\b/i',
                    '/\binjury\b.*\bpoisoning\b/i',
                ],
            ],
            'external_causes' => [
                'name' => 'External causes of morbidity and mortality',
                'slug' => 'external-causes',
                'slug_hints' => ['external-causes', 'morbidity', 'mortality'],
                'patterns' => [
                    '/\bexternal causes\b.*\bmorbidity\b.*\bmortality\b/i',
                    '/\bexternal causes\b/i',
                ],
            ],
            'factors' => [
                'name' => 'Factors influencing health status and contact with health services',
                'slug' => 'factors',
                'slug_hints' => ['factors', 'health status', 'contact'],
                'patterns' => [
                    '/\bfactors\b.*\bhealth status\b.*\bcontact\b.*\bhealth services\b/i',
                    '/\bcontact\b.*\bhealth services\b/i',
                ],
            ],
        ];
    }

    private function normalize(string $s): string
    {
        $s = trim($s);
        // fix your imported typo-like chars / weird punctuation
        $s = str_replace([';', '“', '”', '"'], [' ', '"', '"', ''], $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return strtolower($s);
    }

    private function matches(string $nameN, string $slugN, array $def): bool
    {
        // 1) slug hints
        foreach (($def['slug_hints'] ?? []) as $hint) {
            $hint = strtolower(trim($hint));
            if ($hint !== '' && str_contains($slugN, $hint)) {
                return true;
            }
        }

        // 2) regex patterns on name
        foreach (($def['patterns'] ?? []) as $rx) {
            if (@preg_match($rx, $nameN)) {
                return true;
            }
        }

        // 3) prefix match (for truncation): if stored name is prefix of canonical
        $canonicalN = $this->normalize($def['name']);
        if ($nameN !== '' && str_starts_with($canonicalN, $nameN)) {
            return true;
        }

        return false;
    }

    private function scoreMatch(string $nameN, string $slugN, array $def): int
    {
        $score = 0;

        // slug hint scoring
        foreach (($def['slug_hints'] ?? []) as $hint) {
            $hint = strtolower(trim($hint));
            if ($hint !== '' && str_contains($slugN, $hint)) {
                $score += 25;
            }
        }

        // regex match scoring
        foreach (($def['patterns'] ?? []) as $rx) {
            if (@preg_match($rx, $nameN)) {
                $score += 30;
            }
        }

        // prefix match scoring
        $canonicalN = $this->normalize($def['name']);
        if ($nameN !== '' && str_starts_with($canonicalN, $nameN)) {
            $score += 20;
        }

        // token overlap scoring
        $tokensName = array_filter(explode(' ', preg_replace('/[^a-z ]/i', ' ', $nameN) ?? $nameN));
        $tokensCan  = array_filter(explode(' ', preg_replace('/[^a-z ]/i', ' ', $canonicalN) ?? $canonicalN));

        $tokensName = array_unique($tokensName);
        $tokensCan  = array_unique($tokensCan);

        $common = array_intersect($tokensName, $tokensCan);
        $score += min(25, count($common) * 3);

        return $score;
    }
}
