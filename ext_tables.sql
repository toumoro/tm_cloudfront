#
# Queue of pending CloudFront invalidations ("table" mode).
# uid, pid, tstamp and crdate are added automatically from the TCA.
#
CREATE TABLE tx_tmcloudfront_domain_model_invalidation
(
	pathsegment    text,
	distributionId varchar(255) DEFAULT '' NOT NULL,
	id             varchar(32) DEFAULT '' NOT NULL,

	KEY distribution (distributionId),
	KEY id (id)
);
