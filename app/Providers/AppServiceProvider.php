<?php

namespace App\Providers;

use App\Contracts\AI\MaterialProfileAnalysisProvider;
use App\Contracts\AI\QuestionGenerationProvider;
use App\Contracts\Materials\MaterialFileStore;
use App\Services\AI\GeminiMaterialProfileProvider;
use App\Services\AI\GeminiQuestionGenerationProvider;
use App\Services\Materials\Extraction\DocxExtractor;
use App\Services\Materials\Extraction\MaterialExtractorRouter;
use App\Services\Materials\Extraction\PdfExtractor;
use App\Services\Materials\Extraction\TxtExtractor;
use App\Services\Materials\MaterialStorageService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MaterialFileStore::class, MaterialStorageService::class);
        $this->app->bind(QuestionGenerationProvider::class, GeminiQuestionGenerationProvider::class);
        $this->app->bind(MaterialProfileAnalysisProvider::class, GeminiMaterialProfileProvider::class);
        $this->app->bind(MaterialExtractorRouter::class, function (): MaterialExtractorRouter {
            return new MaterialExtractorRouter(
                new TxtExtractor,
                new PdfExtractor,
                new DocxExtractor,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
