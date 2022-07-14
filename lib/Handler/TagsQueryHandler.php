<?php

declare(strict_types=1);

namespace Netgen\Layouts\Ez\TagsQuery\Handler;

use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\SearchService;
use eZ\Publish\API\Repository\Values\Content\Content;
use eZ\Publish\API\Repository\Values\Content\Field;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\LocationQuery;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Search\SearchHit;
use eZ\Publish\API\Repository\Values\ValueObject;
use eZ\Publish\Core\Helper\TranslationHelper;
use eZ\Publish\Core\MVC\ConfigResolverInterface;
use eZ\Publish\SPI\Persistence\Content\ObjectState\Handler as ObjectStateHandler;
use Netgen\Layouts\API\Values\Collection\Query;
use Netgen\Layouts\Collection\QueryType\QueryTypeHandlerInterface;
use Netgen\Layouts\Ez\Collection\QueryType\Handler\Traits;
use Netgen\Layouts\Ez\ContentProvider\ContentProviderInterface;
use Netgen\Layouts\Ez\Parameters\ParameterType as EzParameterType;
use Netgen\Layouts\Parameters\ParameterBuilderInterface;
use Netgen\Layouts\Parameters\ParameterType;
use Netgen\TagsBundle\API\Repository\Values\Content\Query\Criterion\TagId;
use Netgen\TagsBundle\API\Repository\Values\Tags\Tag;
use Netgen\TagsBundle\Core\FieldType\Tags\Value as TagsFieldValue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Kernel;

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function is_int;

/**
 * Query handler implementation providing values through eZ Platform Tags field.
 */
final class TagsQueryHandler implements QueryTypeHandlerInterface
{
    use Traits\ContentTypeFilterTrait;
    use Traits\CurrentLocationFilterTrait;
    use Traits\MainLocationFilterTrait;
    use Traits\ObjectStateFilterTrait;
    use Traits\ParentLocationTrait;
    use Traits\QueryTypeFilterTrait;
    use Traits\SortTrait;

    private SearchService $searchService;

    private ConfigResolverInterface $configResolver;

    private RequestStack $requestStack;

    private TranslationHelper $translationHelper;

    public function __construct(
        LocationService $locationService,
        SearchService $searchService,
        ObjectStateHandler $objectStateHandler,
        ContentProviderInterface $contentProvider,
        ConfigResolverInterface $configResolver,
        RequestStack $requestStack,
        TranslationHelper $translationHelper
    ) {
        $this->searchService = $searchService;
        $this->configResolver = $configResolver;
        $this->requestStack = $requestStack;
        $this->translationHelper = $translationHelper;

        $this->setLocationService($locationService);
        $this->setObjectStateHandler($objectStateHandler);
        $this->setContentProvider($contentProvider);
    }

    public function buildParameters(ParameterBuilderInterface $builder): void
    {
        $advancedGroup = [self::GROUP_ADVANCED];

        $this->buildParentLocationParameters($builder);

        $builder->add(
            'filter_by_tags',
            EzParameterType\TagsType::class,
            [
                'allow_invalid' => true,
            ],
        );

        $builder->add(
            'use_tags_from_current_content',
            ParameterType\Compound\BooleanType::class,
            [
                'groups' => $advancedGroup,
            ],
        );

        $builder->get('use_tags_from_current_content')->add(
            'field_definition_identifier',
            ParameterType\TextLineType::class,
            [
                'groups' => $advancedGroup,
            ],
        );

        $builder->add(
            'use_tags_from_query_string',
            ParameterType\Compound\BooleanType::class,
            [
                'groups' => $advancedGroup,
            ],
        );

        $builder->get('use_tags_from_query_string')->add(
            'query_string_param_name',
            ParameterType\TextLineType::class,
            [
                'groups' => $advancedGroup,
            ],
        );

        $builder->add(
            'tags_filter_logic',
            ParameterType\ChoiceType::class,
            [
                'required' => true,
                'options' => [
                    'Match any tags' => 'any',
                    'Match all tags' => 'all',
                ],
                'groups' => $advancedGroup,
            ],
        );

        $this->buildSortParameters($builder, [], ['date_published', 'date_modified', 'content_name']);

        $this->buildQueryTypeParameters($builder, $advancedGroup);
        $this->buildMainLocationParameters($builder, $advancedGroup);
        $this->buildContentTypeFilterParameters($builder, $advancedGroup);
        $this->buildCurrentLocationParameters($builder, $advancedGroup);
        $this->buildObjectStateFilterParameters($builder, $advancedGroup);
    }

    public function getValues(Query $query, int $offset = 0, ?int $limit = null): iterable
    {
        $parentLocation = $this->getParentLocation($query);

        if (!$parentLocation instanceof Location) {
            return [];
        }

        $tagIds = $this->getTagIds($query);

        if (count($tagIds) === 0) {
            return [];
        }

        $locationQuery = $this->buildLocationQuery($query, $parentLocation, $tagIds);
        $locationQuery->offset = $this->getOffset($offset);
        $locationQuery->limit = $this->getLimit($limit) ?? $locationQuery->limit;

        // We're disabling query count for performance reasons, however
        // it can only be disabled if limit is not 0
        $locationQuery->performCount = $locationQuery->limit === 0;

        $searchResult = $this->searchService->findLocations(
            $locationQuery,
            ['languages' => $this->configResolver->getParameter('languages')],
        );

        return array_map(
            static fn (SearchHit $searchHit): ValueObject => $searchHit->valueObject,
            $searchResult->searchHits,
        );
    }

    public function getCount(Query $query): int
    {
        $parentLocation = $this->getParentLocation($query);

        if (!$parentLocation instanceof Location) {
            return 0;
        }

        $tagIds = $this->getTagIds($query);

        if (count($tagIds) === 0) {
            return 0;
        }

        $locationQuery = $this->buildLocationQuery($query, $parentLocation, $tagIds);
        $locationQuery->limit = 0;

        $searchResult = $this->searchService->findLocations(
            $locationQuery,
            ['languages' => $this->configResolver->getParameter('languages')],
        );

        return $searchResult->totalCount ?? 0;
    }

    public function isContextual(Query $query): bool
    {
        return $query->getParameter('use_current_location')->getValue() === true
            || $query->getParameter('use_tags_from_current_content')->getValue() === true;
    }

    /**
     * Return filtered offset value to use.
     */
    private function getOffset(int $offset): int
    {
        return $offset >= 0 ? $offset : 0;
    }

    /**
     * Return filtered limit value to use.
     */
    private function getLimit(?int $limit = null): ?int
    {
        if (is_int($limit) && $limit >= 0) {
            return $limit;
        }

        return null;
    }

    /**
     * Returns a list of tag IDs.
     *
     * @return int[]
     */
    private function getTagIds(Query $query): array
    {
        $tags = [];

        if (!$query->getParameter('filter_by_tags')->isEmpty()) {
            $tags[] = array_values($query->getParameter('filter_by_tags')->getValue());
        }

        if ($query->getParameter('use_tags_from_current_content')->getValue() === true) {
            $tags[] = $this->getTagsFromContent($query);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request && $query->getParameter('use_tags_from_query_string')->getValue() === true) {
            $queryStringParam = $query->getParameter('query_string_param_name');

            if (!$queryStringParam->isEmpty() && $request->query->has($queryStringParam->getValue())) {
                $tags[] = Kernel::VERSION_ID >= 50100 ?
                    $request->query->all($queryStringParam->getValue()) :
                    (array) ($request->query->get($queryStringParam->getValue()) ?? []);
            }
        }

        return array_map('intval', array_unique(array_merge(...$tags)));
    }

    /**
     * Builds the Location query from given parameters.
     *
     * @param int[] $tagIds
     */
    private function buildLocationQuery(Query $query, Location $parentLocation, array $tagIds): LocationQuery
    {
        $tagsCriteria = array_map(static fn (int $tagId): TagId => new TagId($tagId), $tagIds);

        $criteria = [
            new Criterion\Subtree($parentLocation->pathString),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
            $this->getQueryTypeFilterCriteria($query, $parentLocation),
            $query->getParameter('tags_filter_logic')->getValue() === 'any' ?
                new Criterion\LogicalOr($tagsCriteria) :
                new Criterion\LogicalAnd($tagsCriteria),
            $this->getMainLocationFilterCriteria($query),
            $this->getContentTypeFilterCriteria($query),
            $this->getObjectStateFilterCriteria($query),
        ];

        $currentLocation = $this->contentProvider->provideLocation();
        if ($currentLocation instanceof Location) {
            $criteria[] = $this->getCurrentLocationFilterCriteria($query, $currentLocation);
        }

        $criteria = array_filter(
            $criteria,
            static fn (?Criterion $criterion): bool => $criterion instanceof Criterion,
        );

        $locationQuery = new LocationQuery();
        $locationQuery->filter = new Criterion\LogicalAnd($criteria);
        $locationQuery->sortClauses = $this->getSortClauses($query, $parentLocation);

        return $locationQuery;
    }

    /**
     * @return string[]
     */
    private function getValidFieldIdentifiers(Query $query, Content $content): array
    {
        $parameter = $query->getParameter('field_definition_identifier');

        if ($parameter->isEmpty()) {
            return array_map(
                static fn (Field $field): string => $field->fieldDefIdentifier,
                $content->fields,
            );
        }

        return array_map('trim', explode(',', $parameter->getValue()));
    }

    /**
     * @return int[]
     */
    private function getTagsFromContent(Query $query): array
    {
        $content = $this->contentProvider->provideContent();

        if ($content === null) {
            return [];
        }

        $fieldIdentifiers = $this->getValidFieldIdentifiers($query, $content);

        $tags = [];

        foreach ($fieldIdentifiers as $fieldIdentifier) {
            $tags[] = $this->getTagsFromField($content, $fieldIdentifier);
        }

        return array_merge(...$tags);
    }

    /**
     * @return int[]
     */
    private function getTagsFromField(Content $content, string $fieldDefinitionIdentifier): array
    {
        $field = $this->translationHelper->getTranslatedField($content, $fieldDefinitionIdentifier);
        if ($field === null || !$field->value instanceof TagsFieldValue) {
            return [];
        }

        return array_map(
            static fn (Tag $tag): int => (int) $tag->id,
            $field->value->tags,
        );
    }
}
