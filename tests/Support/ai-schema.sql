CREATE TABLE "crops" (
"id" INTEGER PRIMARY KEY AUTOINCREMENT,
"name" TEXT NOT NULL,
"slug" TEXT NOT NULL UNIQUE,
"aliases" TEXT,
"is_active" INTEGER NOT NULL DEFAULT 1,
"created_at" TEXT,
"updated_at" TEXT
);
CREATE TABLE "product_purposes" (
"id" INTEGER PRIMARY KEY AUTOINCREMENT,
"name" TEXT NOT NULL UNIQUE,
"slug" TEXT NOT NULL UNIQUE,
"description" TEXT,
"aliases" TEXT,
"is_active" INTEGER NOT NULL DEFAULT 1,
"created_at" TEXT,
"updated_at" TEXT
);
CREATE TABLE "crop_growth_stages" (
"id" INTEGER PRIMARY KEY AUTOINCREMENT,
"crop_id" INTEGER NOT NULL,
"name" TEXT NOT NULL,
"slug" TEXT NOT NULL,
"description" TEXT,
"aliases" TEXT,
"sort_order" INTEGER NOT NULL DEFAULT 0,
"is_active" INTEGER NOT NULL DEFAULT 1,
"created_at" TEXT,
"updated_at" TEXT,
UNIQUE ("crop_id", "slug"),
UNIQUE ("id", "crop_id"),
FOREIGN KEY ("crop_id") REFERENCES "crops" ("id") ON DELETE RESTRICT ON UPDATE NO ACTION
);
CREATE INDEX "cgs_crop_active_sort_idx" ON "crop_growth_stages" ("crop_id", "is_active", "sort_order");
CREATE TABLE "product_usage_guides" (
"id" INTEGER PRIMARY KEY AUTOINCREMENT,
"variant_id" INTEGER NOT NULL,
"crop_id" INTEGER NOT NULL,
"purpose_id" INTEGER NOT NULL,
"growth_stage_scope" TEXT NOT NULL DEFAULT 'unspecified' CHECK ("growth_stage_scope" IS NULL OR "growth_stage_scope" IN ('unspecified','specific','all')),
"application_method" TEXT NOT NULL,
"dose_unit" TEXT CHECK ("dose_unit" IS NULL OR "dose_unit" IN ('g','ml')),
"dose_per_ha_min" NUMERIC,
"dose_per_ha_max" NUMERIC,
"dose_per_liter_min" NUMERIC,
"dose_per_liter_max" NUMERIC,
"water_l_per_ha_min" NUMERIC,
"water_l_per_ha_max" NUMERIC,
"pre_harvest_days" INTEGER,
"instructions" TEXT NOT NULL,
"safety_warning" TEXT,
"label_source" TEXT,
"is_active" INTEGER NOT NULL DEFAULT 0,
"created_at" TEXT,
"updated_at" TEXT,
UNIQUE ("id", "crop_id"),
CONSTRAINT "pug_area_dose_valid" CHECK ((dose_per_ha_min IS NULL AND dose_per_ha_max IS NULL) OR (dose_per_ha_min IS NOT NULL AND dose_per_ha_max IS NOT NULL AND dose_per_ha_min > 0 AND dose_per_ha_max >= dose_per_ha_min)),
CONSTRAINT "pug_water_dose_valid" CHECK ((dose_per_liter_min IS NULL AND dose_per_liter_max IS NULL) OR (dose_per_liter_min IS NOT NULL AND dose_per_liter_max IS NOT NULL AND dose_per_liter_min > 0 AND dose_per_liter_max >= dose_per_liter_min)),
CONSTRAINT "pug_water_volume_valid" CHECK ((water_l_per_ha_min IS NULL AND water_l_per_ha_max IS NULL) OR (water_l_per_ha_min IS NOT NULL AND water_l_per_ha_max IS NOT NULL AND water_l_per_ha_min > 0 AND water_l_per_ha_max >= water_l_per_ha_min)),
CONSTRAINT "pug_dose_requires_unit" CHECK (dose_unit IS NOT NULL OR (dose_per_ha_min IS NULL AND dose_per_liter_min IS NULL)),
CONSTRAINT "pug_active_requires_source" CHECK (is_active = false OR (label_source IS NOT NULL AND CHAR_LENGTH(TRIM(label_source)) > 0 AND CHAR_LENGTH(TRIM(instructions)) > 0)),
FOREIGN KEY ("variant_id") REFERENCES "product_variants" ("id") ON DELETE RESTRICT ON UPDATE NO ACTION,
FOREIGN KEY ("crop_id") REFERENCES "crops" ("id") ON DELETE RESTRICT ON UPDATE NO ACTION,
FOREIGN KEY ("purpose_id") REFERENCES "product_purposes" ("id") ON DELETE RESTRICT ON UPDATE NO ACTION
);
CREATE INDEX "pug_variant_active_idx" ON "product_usage_guides" ("variant_id", "is_active");
CREATE INDEX "pug_crop_active_idx" ON "product_usage_guides" ("crop_id", "is_active");
CREATE INDEX "pug_purpose_crop_active_idx" ON "product_usage_guides" ("purpose_id", "crop_id", "is_active");
CREATE TABLE "product_usage_guide_growth_stages" (
"guide_id" INTEGER NOT NULL,
"growth_stage_id" INTEGER NOT NULL,
"crop_id" INTEGER NOT NULL,
PRIMARY KEY ("guide_id", "growth_stage_id"),
FOREIGN KEY ("guide_id", "crop_id") REFERENCES "product_usage_guides" ("id", "crop_id") ON DELETE CASCADE ON UPDATE RESTRICT,
FOREIGN KEY ("growth_stage_id", "crop_id") REFERENCES "crop_growth_stages" ("id", "crop_id") ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE INDEX "puggs_guide_crop_idx" ON "product_usage_guide_growth_stages" ("guide_id", "crop_id");
CREATE INDEX "puggs_stage_crop_guide_idx" ON "product_usage_guide_growth_stages" ("growth_stage_id", "crop_id", "guide_id");
CREATE TABLE "ai_knowledge_documents" (
"id" INTEGER PRIMARY KEY AUTOINCREMENT,
"product_id" INTEGER,
"usage_guide_id" INTEGER,
"chunk_index" INTEGER NOT NULL DEFAULT 0,
"content_text" TEXT NOT NULL,
"content_hash" TEXT NOT NULL,
"vector_point_id" TEXT NOT NULL UNIQUE,
"status" TEXT NOT NULL DEFAULT 'pending' CHECK ("status" IS NULL OR "status" IN ('pending','indexing','ready','failed')),
"indexed_at" TEXT,
"last_error" TEXT,
"created_at" TEXT,
"updated_at" TEXT,
UNIQUE ("product_id", "chunk_index"),
UNIQUE ("usage_guide_id", "chunk_index"),
CONSTRAINT "akd_exact_source_check" CHECK ((product_id IS NOT NULL AND usage_guide_id IS NULL) OR (usage_guide_id IS NOT NULL AND product_id IS NULL)),
CONSTRAINT "akd_content_not_blank" CHECK (CHAR_LENGTH(TRIM(content_text)) > 0),
CONSTRAINT "akd_ready_has_indexed_at" CHECK (status <> 'ready' OR indexed_at IS NOT NULL),
FOREIGN KEY ("product_id") REFERENCES "products" ("id") ON DELETE RESTRICT ON UPDATE NO ACTION,
FOREIGN KEY ("usage_guide_id") REFERENCES "product_usage_guides" ("id") ON DELETE RESTRICT ON UPDATE NO ACTION
);
CREATE INDEX "akd_status_updated_idx" ON "ai_knowledge_documents" ("status", "updated_at");