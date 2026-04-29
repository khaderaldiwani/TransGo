<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GovernorateAlias extends Model
{
    protected $table = 'governorate_aliases';
    protected $primaryKey = 'alias_id';

    protected $fillable = [
        'governorate_id',
        'alias',
        'normalized_alias',
    ];

    protected $casts = [
        'alias_id' => 'integer',
        'governorate_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (GovernorateAlias $alias): void {
            $alias->normalized_alias = self::normalize($alias->alias);
        });
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class, 'governorate_id', 'governorate_id');
    }

    public static function normalize(?string $value): string
    {
        $normalized = Str::lower(trim((string) $value));

        $normalized = str_replace(
            ['أ', 'إ', 'آ', 'ة', 'ى', 'ؤ', 'ئ'],
            ['ا', 'ا', 'ا', 'ه', 'ي', 'و', 'ي'],
            $normalized
        );

        $normalized = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $normalized) ?? $normalized;
        $normalized = str_replace(['-', '_', ',', '،', '.', '/', '\\'], ' ', $normalized);
        $normalized = preg_replace('/\b(muhafazat|muhafaza|governorate|province|syria)\b/u', '', $normalized) ?? $normalized;
        $normalized = str_replace(['محافظة', 'محافظه', 'سوريا'], '', $normalized);

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }
}
