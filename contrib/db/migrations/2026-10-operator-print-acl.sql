--
-- daloRADIUS operator printing ACL repair
--
-- Apply this script when upgrading an existing daloRADIUS installation whose
-- operator ACL catalog predates the Printing permission.
--

INSERT INTO operators_acl_files (file, category, section)
SELECT 'mng_print', 'Management', 'Printing'
WHERE NOT EXISTS (
    SELECT 1
    FROM operators_acl_files
    WHERE file = 'mng_print'
);

INSERT INTO operators_acl (operator_id, file, access)
SELECT operators.id, 'mng_print',
       CASE WHEN operators.username = 'administrator' THEN 1 ELSE 0 END
FROM operators
WHERE NOT EXISTS (
    SELECT 1
    FROM operators_acl
    WHERE operators_acl.operator_id = operators.id
      AND operators_acl.file = 'mng_print'
);
