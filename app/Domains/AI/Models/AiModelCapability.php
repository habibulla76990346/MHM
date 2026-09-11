<?php

namespace App\Domains\AI\Models;

use App\Domains\AI\Support\Capability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelCapability extends Model
{
    protected $fillable = ['model_id', 'capability', 'is_supported', 'metadata'];

    protected function casts(): array
    {
        return ['is_supported' => 'boolean', 'metadata' => 'array'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /**
     * Make the stored capabilities match the given list exactly.
     *
     * Rows are updated rather than deleted and recreated, so any metadata an
     * adapter recorded against a capability survives an administrator ticking
     * a different box.
     *
     * @param  array<int, string>  $capabilities
     */
    public static function syncForModel(AiModel $model, array $capabilities): void
    {
        $capabilities = array_values(array_filter(
            $capabilities,
            fn ($c) => is_string($c) && Capability::exists($c),
        ));

        foreach ($capabilities as $capability) {
            static::updateOrCreate(
                ['model_id' => $model->getKey(), 'capability' => $capability],
                ['is_supported' => true],
            );
        }

        // Anything no longer ticked is marked unsupported rather than removed,
        // so "we checked and it cannot" is distinguishable from "nobody has
        // said".
        static::where('model_id', $model->getKey())
            ->when($capabilities !== [], fn ($q) => $q->whereNotIn('capability', $capabilities))
            ->update(['is_supported' => false]);
    }
}
