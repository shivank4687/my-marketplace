<?php

namespace Webkul\DataTransfer\Helpers\Importers\Category;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\DataTransfer\Contracts\ImportBatch as ImportBatchContract;
use Webkul\DataTransfer\Helpers\Importers\AbstractImporter;
use Webkul\DataTransfer\Repositories\ImportBatchRepository;

class Importer extends AbstractImporter
{
    /**
     * Error code for non existing parent category
     */
    const ERROR_PARENT_NOT_FOUND = 'parent_not_found';

    /**
     * Error code for duplicate slug
     */
    const ERROR_DUPLICATE_SLUG = 'duplicate_slug';

    /**
     * Permanent entity columns
     */
    protected array $validColumnNames = [
        'name',
        'slug',
        'parent_id',
        'position',
        'status',
        'description',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'locale',
    ];

    /**
     * Permanent entity columns
     */
    protected array $permanentAttributes = ['slug'];

    /**
     * Error message templates
     */
    protected array $messages = [
        self::ERROR_PARENT_NOT_FOUND => 'data_transfer::app.importers.categories.validation.errors.parent-not-found',
        self::ERROR_DUPLICATE_SLUG   => 'data_transfer::app.importers.categories.validation.errors.duplicate-slug',
    ];

    /**
     * Permanent entity column
     */
    protected string $masterAttributeCode = 'slug';

    /**
     * Cached slugs for duplicate check
     */
    protected array $slugs = [];

    /**
     * Create a new helper instance.
     */
    public function __construct(
        protected ImportBatchRepository $importBatchRepository,
        protected CategoryRepository $categoryRepository
    ) {
        parent::__construct($importBatchRepository);
    }

    /**
     * Validate a single row of data.
     */
    public function validateRow(array $rowData, int $rowNumber): bool
    {
        // Check required fields
        $validator = Validator::make($rowData, [
            'name'   => 'required',
            'slug'   => 'required',
            'status' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->keys() as $field) {
                $this->skipRow($rowNumber, self::ERROR_CODE_INVALID_ATTRIBUTE, $field, $validator->errors()->first($field));
            }
            return false;
        }

        // Check for duplicate slug in batch
        if (in_array($rowData['slug'], $this->slugs)) {
            $this->skipRow($rowNumber, self::ERROR_DUPLICATE_SLUG, 'slug');
            return false;
        }
        $this->slugs[] = $rowData['slug'];

        // Optionally: Check if parent_id exists (if provided)
        if (!empty($rowData['parent_id']) && !$this->categoryRepository->find($rowData['parent_id'])) {
            $this->skipRow($rowNumber, self::ERROR_PARENT_NOT_FOUND, 'parent_id');
            return false;
        }

        return true;
    }

    /**
     * Import a batch of categories.
     */
    public function importBatch(ImportBatchContract $batch): bool
    {
        Event::dispatch('data_transfer.imports.batch.import.before', $batch);

        $channelRootId = core()->getCurrentChannel()->root_category_id;

        foreach ($batch->data as $rowData) {
            // Prepare data for repository
            $data = Arr::only($rowData, $this->validColumnNames);

            // Set locale from CSV if present and not empty, otherwise use app locale
            $data['locale'] = (!empty($rowData['locale'])) ? $rowData['locale'] : app()->getLocale();

            // Set parent_id to channel root if missing/empty
            if (empty($data['parent_id'])) {
                $data['parent_id'] = $channelRootId;
            }

            // Optionally support display_mode, logo_path, banner_path if present in CSV
            foreach (['display_mode', 'logo_path', 'banner_path'] as $optionalField) {
                if (isset($rowData[$optionalField])) {
                    $data[$optionalField] = $rowData[$optionalField];
                }
            }

            // Check if category with this slug exists
            $existing = $this->categoryRepository->getModel()->whereTranslation('slug', $data['slug'])->first();

            if ($existing) {
                $existing->update($data);
                $this->updatedItemsCount++;
            } else {
                $this->categoryRepository->create($data);
                $this->createdItemsCount++;
            }
        }

        // Rebuild the nested set tree after import
        \Webkul\Category\Models\Category::fixTree();

        $this->importBatchRepository->update([
            'state'   => 'processed',
            'summary' => [
                'created' => $this->getCreatedItemsCount(),
                'updated' => $this->getUpdatedItemsCount(),
            ],
        ], $batch->id);

        Event::dispatch('data_transfer.imports.batch.import.after', $batch);

        return true;
    }
}
