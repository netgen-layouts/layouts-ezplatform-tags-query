<?php

namespace Netgen\Layouts\TagsQuery\Handler;

use Exception;
use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\API\Repository\Exceptions\NotFoundException;
use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\SearchService;
use eZ\Publish\API\Repository\Values\Content\Content;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\LocationQuery;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Query\SortClause;
use eZ\Publish\API\Repository\Values\Content\Search\SearchHit;
use Netgen\TagsBundle\API\Repository\Values\Content\Query\Criterion\TagId;
use Netgen\TagsBundle\API\Repository\Values\Tags\Tag;
use Netgen\TagsBundle\Core\FieldType\Tags\Value as TagsFieldValue;
use eZ\Publish\Core\Helper\TranslationHelper;
use eZ\Publish\SPI\Persistence\Content\Type\Handler;
use Netgen\BlockManager\API\Values\Collection\Query;
use Netgen\BlockManager\Collection\QueryType\QueryTypeHandlerInterface;
use Netgen\BlockManager\Ez\ContentProvider\ContentProviderInterface;
use Netgen\BlockManager\Ez\Parameters\ParameterType as EzParameterType;
use Netgen\BlockManager\Parameters\ParameterBuilderInterface;
use Netgen\BlockManager\Parameters\ParameterType;
use Netgen\BlockManager\Version;

/**
 * Query handler implementation providing values through eZ Platform Tags field.
 */
class TagsQueryHandler implements QueryTypeHandlerInterface
{
    /**
     * @var int
     */
    const DEFAULT_LIMIT = 25;

    /**
     * @var \eZ\Publish\API\Repository\LocationService
     */
    private $locationService;

    /**
     * @var \eZ\Publish\API\Repository\ContentService
     */
    private $contentService;

    /**
     * @var \eZ\Publish\API\Repository\SearchService
     */
    private $searchService;

    /**
     * @var \eZ\Publish\SPI\Persistence\Content\Type\Handler
     */
    private $contentTypeHandler;

    /**
     * @var \eZ\Publish\Core\Helper\TranslationHelper
     */
    private $translationHelper;

    /**
     * @var \Netgen\BlockManager\Ez\ContentProvider\ContentProviderInterface
     */
    private $contentProvider;

    /**
     * Injected list of prioritized languages.
     *
     * @var array
     */
    private $languages = array();

    /**
     * @var array
     */
    private $sortClauses = array(
        'default' => SortClause\DatePublished::class,
        'date_published' => SortClause\DatePublished::class,
        'date_modified' => SortClause\DateModified::class,
        'content_name' => SortClause\ContentName::class,
        'location_priority' => SortClause\Location\Priority::class,
        Location::SORT_FIELD_PATH => SortClause\Location\Path::class,
        Location::SORT_FIELD_PUBLISHED => SortClause\DatePublished::class,
        Location::SORT_FIELD_MODIFIED => SortClause\DateModified::class,
        Location::SORT_FIELD_SECTION => SortClause\SectionIdentifier::class,
        Location::SORT_FIELD_DEPTH => SortClause\Location\Depth::class,
        Location::SORT_FIELD_PRIORITY => SortClause\Location\Priority::class,
        Location::SORT_FIELD_NAME => SortClause\ContentName::class,
        Location::SORT_FIELD_NODE_ID => SortClause\Location\Id::class,
        Location::SORT_FIELD_CONTENTOBJECT_ID => SortClause\ContentId::class,
    );

    /**
     * @var array
     */
    private $advancedGroups = array();

    public function __construct(
        LocationService $locationService,
        ContentService $contentService,
        SearchService $searchService,
        Handler $contentTypeHandler,
        TranslationHelper $translationHelper,
        ContentProviderInterface $contentProvider
    ) {
        $this->locationService = $locationService;
        $this->contentService = $contentService;
        $this->searchService = $searchService;
        $this->contentTypeHandler = $contentTypeHandler;
        $this->translationHelper = $translationHelper;
        $this->contentProvider = $contentProvider;

        if (Version::VERSION_ID >= 800) {
            $this->advancedGroups = array('advanced');
        }
    }

    /**
     * Sets the current siteaccess languages into the handler.
     *
     * @param array $languages
     */
    public function setLanguages(array $languages = null)
    {
        $this->languages = is_array($languages) ? $languages : array();
    }

    public function buildParameters(ParameterBuilderInterface $builder)
    {
        $builder->add(
            'use_current_location',
            ParameterType\Compound\BooleanType::class,
            array(
                'reverse' => true,
            )
        );

        $builder->get('use_current_location')->add(
            'parent_location_id',
            EzParameterType\LocationType::class
        );

        $builder->add(
            'filter_by_tags',
            EzParameterType\TagsType::class
        );

        $builder->add(
            'use_tags_from_current_content',
            ParameterType\Compound\BooleanType::class,
            array(
                'groups' => array(self::GROUP_ADVANCED),
            )
        );

        $builder->get('use_tags_from_current_content')->add(
            'field_definition_identifier',
            ParameterType\TextLineType::class,
            array(
                'default_value' => 'Text',
                'groups' => array(self::GROUP_ADVANCED),
            )
        );

        $builder->add(
            'tags_filter_logic',
            ParameterType\ChoiceType::class,
            array(
                'required' => true,
                'options' => array(
                    'Match any tags' => 'or_logic',
                    'Match all tags' => 'and_logic',
                ),
                'groups' => array(self::GROUP_ADVANCED),
            )
        );

        $builder->add(
            'sort_type',
            ParameterType\ChoiceType::class,
            array(
                'required' => true,
                'options' => array(
                    'Published' => 'date_published',
                    'Modified' => 'date_modified',
                    'Alphabetical' => 'content_name',
                ),
            )
        );

        $builder->add(
            'sort_direction',
            ParameterType\ChoiceType::class,
            array(
                'required' => true,
                'options' => array(
                    'Descending' => LocationQuery::SORT_DESC,
                    'Ascending' => LocationQuery::SORT_ASC,
                ),
            )
        );

        $builder->add(
            'query_type',
            ParameterType\ChoiceType::class,
            array(
                'required' => true,
                'options' => array(
                    'List' => 'list',
                    'Tree' => 'tree',
                ),
                'groups' => array(self::GROUP_ADVANCED),
            )
        );

        $builder->add(
            'limit',
            ParameterType\IntegerType::class,
            array(
                'min' => 0,
            )
        );

        $builder->add(
            'offset',
            ParameterType\IntegerType::class,
            array(
                'min' => 0,
                'groups' => $this->advancedGroups,
            )
        );

        $builder->add(
            'only_main_locations',
            ParameterType\BooleanType::class,
            array(
                'default_value' => true,
                'groups' => $this->advancedGroups,
            )
        );

        $builder->add(
            'filter_by_content_type',
            ParameterType\Compound\BooleanType::class,
            array(
                'groups' => $this->advancedGroups,
            )
        );

        $builder->get('filter_by_content_type')->add(
            'content_types',
            EzParameterType\ContentTypeType::class,
            array(
                'multiple' => true,
                'groups' => $this->advancedGroups,
            )
        );

        $builder->get('filter_by_content_type')->add(
            'content_types_filter',
            ParameterType\ChoiceType::class,
            array(
                'required' => true,
                'options' => array(
                    'Include content types' => 'include',
                    'Exclude content types' => 'exclude',
                ),
                'groups' => $this->advancedGroups,
            )
        );
    }

    public function getValues(Query $query, $offset = 0, $limit = null)
    {
        $parentLocation = $this->getParentLocation($query);

        if (!$parentLocation instanceof Location) {
            return array();
        }

        $tagIds = $this->getTagIds($query);

        if (count($tagIds) === 0) {
            return array();
        }

        $searchResult = $this->searchService->findLocations(
            $this->buildQuery($parentLocation, $tagIds, $query),
            array('languages' => $this->languages)
        );

        $locations = array_map(
            function (SearchHit $searchHit) {
                return $searchHit->valueObject;
            },
            $searchResult->searchHits
        );

        return $locations;
    }

    public function getCount(Query $query)
    {
        $parentLocation = $this->getParentLocation($query);

        if (!$parentLocation instanceof Location) {
            return 0;
        }

        $tagIds = $this->getTagIds($query);

        if (count($tagIds) === 0) {
            return 0;
        }

        $searchResult = $this->searchService->findLocations(
            $this->buildQuery($parentLocation, $tagIds, $query, true),
            array('languages' => $this->languages)
        );

        return $searchResult->totalCount;
    }

    public function getInternalLimit(Query $query)
    {
        $limit = $query->getParameter('limit')->getValue();

        if (!is_int($limit)) {
            return self::DEFAULT_LIMIT;
        }

        return $limit >= 0 ? $limit : self::DEFAULT_LIMIT;
    }

    public function isContextual(Query $query)
    {
        return $query->getParameter('use_current_location')->getValue() === true
            || $query->getParameter('use_tags_from_current_content')->getValue() === true;
    }

    /**
     * Returns content type IDs for all existing content types.
     *
     * @param array $contentTypeIdentifiers
     *
     * @return array
     */
    private function getContentTypeIds(array $contentTypeIdentifiers)
    {
        $idList = array();

        foreach ($contentTypeIdentifiers as $identifier) {
            try {
                $contentType = $this->contentTypeHandler->loadByIdentifier($identifier);
                $idList[] = $contentType->id;
            } catch (NotFoundException $e) {
                continue;
            }
        }

        return $idList;
    }

    /**
     * Return offset value to use from the given collection $query.
     *
     * @param \Netgen\BlockManager\API\Values\Collection\Query $query
     *
     * @return int
     */
    private function getOffset(Query $query)
    {
        $offset = $query->getParameter('offset')->getValue();

        if (is_int($offset) && $offset >= 0) {
            return $offset;
        }

        return 0;
    }

    /**
     * Returns a list of tag ids.
     *
     * @param \Netgen\BlockManager\API\Values\Collection\Query $query
     *
     * @return int[]|string[]
     */
    private function getTagIds(Query $query)
    {
        $tags = array();
        if (!$query->getParameter('filter_by_tags')->isEmpty()) {
            $tags = array_values($query->getParameter('filter_by_tags')->getValue());
        }

        if (!$query->getParameter('use_tags_from_current_content')->isEmpty()) {
            $tags = array_merge($tags, $this->getTagsFromContent($query));

        }

        return array_unique($tags);
    }

    /**
     * Builds the Location query from given parameters.
     *
     * @param Location $parentLocation
     * @param array $tagIds
     * @param \Netgen\BlockManager\API\Values\Collection\Query $query
     * @param bool $buildCountQuery
     *
     * @return LocationQuery
     */
    private function buildQuery(Location $parentLocation, array $tagIds, Query $query, $buildCountQuery = false)
    {
        $locationQuery = new LocationQuery();
        $internalLimit = $this->getInternalLimit($query);
        $offset = $this->getOffset($query);
        $sortType = $query->getParameter('sort_type')->getValue() ?: 'default';
        $sortDirection = $query->getParameter('sort_direction')->getValue() ?: LocationQuery::SORT_DESC;

        $tagsLogic = $query->getParameter('tags_filter_logic')->getValue();

        $criteria = array(
            new Criterion\Subtree($parentLocation->pathString),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
        );

        if ($query->getParameter('query_type')->getValue() === 'list') {
            $criteria[] = new Criterion\Location\Depth(
                Criterion\Operator::EQ, $parentLocation->depth + 1
            );
        }

        if (count($tagIds) === 1) {
            $criteria[] = new TagId($tagIds[0]);
        } else {

            $tagCriterions = array_map(
                function ($tagId) {
                    return new TagId($tagId);
                },
                $tagIds
            );

            if ($tagsLogic == 'or_logic') {
                $tagsCriteria = new Criterion\LogicalOr($tagCriterions);
            } else {
                $tagsCriteria = new Criterion\LogicalAnd($tagCriterions);
            }

            $criteria[] = $tagsCriteria;
        }

        if ($query->getParameter('only_main_locations')->getValue()) {
            $criteria[] = new Criterion\Location\IsMainLocation(
                Criterion\Location\IsMainLocation::MAIN
            );
        }

        if ($query->getParameter('filter_by_content_type')->getValue()) {
            $contentTypes = $query->getParameter('content_types')->getValue();
            if (!empty($contentTypes)) {
                $contentTypeFilter = new Criterion\ContentTypeId(
                    $this->getContentTypeIds($contentTypes)
                );

                if ($query->getParameter('content_types_filter')->getValue() === 'exclude') {
                    $contentTypeFilter = new Criterion\LogicalNot($contentTypeFilter);
                }

                $criteria[] = $contentTypeFilter;
            }
        }

        $locationQuery->filter = new Criterion\LogicalAnd($criteria);

        $locationQuery->limit = 0;
        if (!$buildCountQuery) {
            $locationQuery->offset = $offset;
            $locationQuery->limit = $internalLimit;
        }

        $locationQuery->sortClauses = array(
            new $this->sortClauses[$sortType]($sortDirection),
        );

        return $locationQuery;
    }

    private function getTagsFromContent(Query $query)
    {
        $content = $this->contentProvider->provideContent();

        if ($content === null) {
            return array();
        }

        $tags = array();
        if (!$query->getParameter('field_definition_identifier')->isEmpty()) {
            $fieldDefinitionIdentifiers = $query->getParameter('field_definition_identifier')->getValue();
            $fieldDefinitionIdentifiers = explode(',', $fieldDefinitionIdentifiers);

            foreach($fieldDefinitionIdentifiers as $fieldDefinitionIdentifier) {
                $tags = array_merge($tags, $this->getTagsFromField($content, trim($fieldDefinitionIdentifier)));
            }

            return $tags;
        }

        return array_merge($tags, $this->getTagsFromAllContentFields($content));
    }

    /**
     * Returns the parent location to use for the query.
     *
     * @param \Netgen\BlockManager\API\Values\Collection\Query $query
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Location|null
     */
    private function getParentLocation(Query $query)
    {
        if ($query->getParameter('use_current_location')->getValue()) {
            return $this->contentProvider->provideLocation();
        }

        $parentLocationId = $query->getParameter('parent_location_id')->getValue();
        if (empty($parentLocationId)) {
            return null;
        }

        try {
            $parentLocation = $this->locationService->loadLocation($parentLocationId);

            return $parentLocation->invisible ? null : $parentLocation;
        } catch (Exception $e) {
            return null;
        }
    }

    private function getTagsFromField(Content $content, $fieldDefinitionIdentifier)
    {
        if (!array_key_exists($fieldDefinitionIdentifier, $content->fields)) {
            return array();
        }

        $field = $this->translationHelper->getTranslatedField(
            $content,
            $fieldDefinitionIdentifier
        );

        if ($field === null || !$field->value instanceof TagsFieldValue) {
            return array();
        }

        return array_map(
            function(Tag $tag) {
                return $tag->id;
            },
            $field->value->tags
        );
    }

    private function getTagsFromAllContentFields(Content $content)
    {
        $tags = array();
        foreach ($content->fields as $fieldDefIdentifier => $value) {
            $tags = array_merge($tags, $this->getTagsFromField($content, $fieldDefIdentifier));
        }

        return $tags;
    }
}