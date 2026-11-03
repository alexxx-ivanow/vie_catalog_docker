-- Рекомендуемые индексы для getFilters / getGoods (создаются автоматически через FilterAggregateSchema::ensurePerformanceIndexes).
-- Проверка: EXPLAIN запросов filters_price_minmax и filters_prop_* с ?perf=1&skip_cache=1

-- CREATE INDEX ix_myapp_cat_sect_elem ON b_iblock_section_element (IBLOCK_SECTION_ID, IBLOCK_ELEMENT_ID);
-- CREATE INDEX ix_myapp_cat_elem_prop ON b_iblock_element_property (IBLOCK_PROPERTY_ID, IBLOCK_ELEMENT_ID, VALUE_ENUM);
-- CREATE INDEX ix_myapp_cat_price_group_product ON b_catalog_price (CATALOG_GROUP_ID, PRODUCT_ID);
