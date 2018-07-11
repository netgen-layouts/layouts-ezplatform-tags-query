<?php

declare(strict_types=1);

namespace Netgen\Layouts\TagsQuery\Handler;

use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\SearchService;
use eZ\Publish\API\Repository\Values\Content\Content;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\LocationQuery;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Search\SearchHit;
use eZ\Publish\Core\Helper\TranslationHelper;
use eZ\Publish\SPI\Persistence\Content\Type\FieldDefinition;
use eZ\Publish\SPI\Persistence\Content\Type\Handler;
use Netgen\BlockManager\API\Values\Collection\Query;
use Netgen\BlockManager\Collection\QueryType\QueryTypeHandlerInterface;
use Netgen\BlockManager\Ez\Collection\QueryType\Handler\Traits\ContentTypeFilterTrait;
use Netgen\BlockManager\Ez\Collection\QueryType\Handler\Traits\MainLocationFilterTrait;
use Netgen\BlockManager\Ez\Collection\QueryType\Handler\Traits\ParentLocationTrait;
use Netgen\BlockManager\Ez\Collection\QueryType\Handler\Traits\QueryTypeFilterTrait;
use Netgen\BlockManager\Ez\Collection\QueryType\Handler\Traits\SortTrait;
use Netgen\BlockManager\Ez\ContentProvider\ContentProviderInterface;
use Netgen\BlockManager\Ez\Parameters\ParameterType as EzParameterType;
use Netgen\BlockManager\Parameters\ParameterBuilderInterface;
use Netgen\BlockManager\Parameters\ParameterType;
use Netgen\TagsBundle\API\Repository\Values\Content\Query\Criterion\TagId;
use Netgen\TagsBundle\API\Repository\Values\Tags\Tag;
use Netgen\TagsBundle\Core\FieldType\Tags\Value as TagsFieldValue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Query handler implementation providing values through eZ Platform Tags field.
 *
 * @final
 */
class TagsQueryHandler implements QueryTypeHandlerInterface
{
    use ParentLocationTrait;
    use ContentTypeFilterTrait;
    use MainLocationFilterTrait;
    use QueryTypeFilterTrait;
    use SortTrait;

    /**
     * @var \eZ\Publish\API\Repository\SearchService
     */
    private $searchService;

    /**
     * @var \eZ\Publish\Core\Helper\TranslationHelper
     */
    private $translationHelper;

    /**
     * Injected list of prioritized languages.
     *
     * @var array
     */
    private $languages = [];

    /**
     * @var \Symfony\Component\HttpFoundation\RequestStack
     */
    private $requestStack;

    public function __construct(
        LocationService $locationService,
        SearchService $searchService,
        Handler $contentTypeHandler,
        TranslationHelper $translationHelper,
        ContentProviderInterface $contentProvider,
        RequestStack $requestStack
    ) {
        $this->searchService = $searchService;
        $this->translationHelper = $translationHelper;
        $this->requestStack = $requestStack;

        $this->setLocationService($locationService);
        $this->setContentTypeHandler($contentTypeHandler);
        $this->setContentProvider($contentProvider);
    }

    /**
     * Sets the current siteaccess languages into the handler.
     */
    public function setLanguages(?array $languages = null): void
    {
        $this->languages = $languages ?? [];
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
            ]
        );

        $builder->add(
            'use_tags_from_current_content',
            ParameterType\Compound\BooleanType::class,
            [
                'groups' => $advancedGroup,
            ]
        );

        $builder->get('use_tags_from_current_content')->add(
            'field_definition_identifier',
            ParameterType\TextLineType::class,
            [
                'groups' => $advancedGroup,
            ]
        );

        $builder->add(
            'use_tags_from_query_string',
            ParameterType\Compound\BooleanType::class,
            [
                'groups' => $advancedGroup,
            ]
        );

        $builder->get('use_tags_from_query_string')->add(
            'query_string_param_name',
            ParameterType\TextLineType::class,
            [
                'groups' => $advancedGroup,
            ]
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
            ]
        );

        $this->buildSortParameters($builder, [], ['date_published', 'date_modified', 'content_name']);

        $this->buildQueryTypeParameters($builder, $advancedGroup);
        $this->buildMainLocationParameters($builder, $advancedGroup);
        $this->buildContentTypeFilterParameters($builder, $advancedGroup);
    }

    public function getValues(Query $query, $offset = 0, $limit = null)
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
            ['languages' => $this->languages]
        );

        $locations = array_map(
            function (SearchHit $searchHit) {
                return $searchHit->valueObject;
            },
            $searchResult->searchHits
        );

        return $locations;
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
            ['languages' => $this->languages]
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
     */
    private function getTagIds(Query $query): array
    {
        $tags = [];

        if (!$query->getParameter('filter_by_tags')->isEmpty()) {
            $tags = array_values($query->getParameter('filter_by_tags')->getValue());
        }

        if ($query->getParameter('use_tags_from_current_content')->getValue() === true) {
            $tags = array_merge($tags, $this->getTagsFromContent($query));
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request && $query->getParameter('use_tags_from_query_string')->getValue() === true) {
            $queryStringParam = $query->getParameter('query_string_param_name');

            if (!$queryStringParam->isEmpty() && $request->query->has($queryStringParam->getValue())) {
                $value = $request->query->get($queryStringParam->getValue());
                if (!is_array($value)) {
                    $value = [$value];
                }

                $tags = array_merge($tags, $value);
            }
        }

        return array_unique($tags);
    }

    /**
     * Builds the Location query from given parameters.
     */
    private function buildLocationQuery(Query $query, Location $parentLocation, array $tagIds): LocationQuery
    {
        $tagsCriteria = array_map(
            function ($tagId): TagId {
                return new TagId($tagId);
            },
            $tagIds
        );

        $tagsCriteria = $query->getParameter('tags_filter_logic')->getValue() === 'any' ?
            new Criterion\LogicalOr($tagsCriteria) :
            new Criterion\LogicalAnd($tagsCriteria);

        $criteria = [
            new Criterion\Subtree($parentLocation->pathString),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
            $this->getQueryTypeFilterCriteria($query, $parentLocation),
            $tagsCriteria,
            $this->getMainLocationFilterCriteria($query),
            $this->getContentTypeFilterCriteria($query),
        ];

        $criteria = array_filter(
            $criteria,
            function ($criterion): bool {
                return $criterion instanceof Criterion;
            }
        );

        $locationQuery = new LocationQuery();
        $locationQuery->filter = new Criterion\LogicalAnd($criteria);
        $locationQuery->sortClauses = $this->getSortClauses($query, $parentLocation);

        return $locationQuery;
    }

    private function getTagsFromContent(Query $query): array
    {
        $content = $this->contentProvider->provideContent();

        if ($content === null) {
            return [];
        }

        $tags = [];
        if (!$query->getParameter('field_definition_identifier')->isEmpty()) {
            $fieldDefinitionIdentifiers = $query->getParameter('field_definition_identifier')->getValue();
            $fieldDefinitionIdentifiers = explode(',', $fieldDefinitionIdentifiers);

            foreach ($fieldDefinitionIdentifiers as $fieldDefinitionIdentifier) {
                $tags = array_merge($tags, $this->getTagsFromField($content, trim($fieldDefinitionIdentifier)));
            }

            return $tags;
        }

        return array_merge($tags, $this->getTagsFromAllContentFields($content));
    }

    private function getTagsFromField(Content $content, string $fieldDefinitionIdentifier): array
    {
        if (!array_key_exists($fieldDefinitionIdentifier, $content->fields)) {
            return [];
        }

        $field = $this->translationHelper->getTranslatedField(
            $content,
            $fieldDefinitionIdentifier
        );

        if ($field === null || !$field->value instanceof TagsFieldValue) {
            return [];
        }

        return array_map(
            function (Tag $tag) {
                return $tag->id;
            },
            $field->value->tags
        );
    }

    private function getTagsFromAllContentFields(Content $content): array
    {
        $tags = [];

        $contentType = $this->contentTypeHandler->load($content->contentInfo->contentTypeId);

        $tagFields = array_map(
            function (FieldDefinition $definition): ?string {
                if ($definition->fieldType === 'eztags') {
                    return $definition->identifier;
                }

                return null;
            },
            $contentType->fieldDefinitions
        );

        $tagFields = array_filter($tagFields);

        foreach ($content->fields as $field) {
            if (!in_array($field->fieldDefIdentifier, $tagFields, true)) {
                continue;
            }

            $tags = array_merge($tags, $this->getTagsFromField($content, $field->fieldDefIdentifier));
        }

        return $tags;
    }
}
