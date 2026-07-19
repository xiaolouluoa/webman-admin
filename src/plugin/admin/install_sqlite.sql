-- SQLite 安装SQL
-- Webman Admin 数据库表结构

CREATE TABLE IF NOT EXISTS wa_admin_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role_id INTEGER NOT NULL,
    admin_id INTEGER NOT NULL,
    UNIQUE(role_id, admin_id)
);

CREATE TABLE IF NOT EXISTS wa_admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(32) NOT NULL UNIQUE,
    nickname VARCHAR(40) NOT NULL,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(255) DEFAULT '/app/admin/avatar.png',
    email VARCHAR(100) DEFAULT NULL,
    mobile VARCHAR(16) DEFAULT NULL,
    created_at DATETIME DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,
    login_at DATETIME DEFAULT NULL,
    status INTEGER DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wa_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(128) NOT NULL UNIQUE,
    value TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT '2022-08-15 00:00:00',
    updated_at DATETIME NOT NULL DEFAULT '2022-08-15 00:00:00'
);

INSERT INTO wa_options (name, value, created_at, updated_at) VALUES
('system_config', '{"logo":{"title":"Webman Admin","image":"/app/admin/admin/images/logo.png"},"menu":{"data":"/app/admin/rule/get?original=1","method":"GET","accordion":true,"collapse":false,"control":false,"controlWidth":500,"select":"0","async":true},"tab":{"enable":true,"keepState":true,"preload":false,"session":true,"max":"30","index":{"id":"0","href":"/app/admin/index/dashboard","title":"仪表盘"}},"theme":{"defaultColor":"2","defaultMenu":"light-theme","defaultHeader":"auto-theme","allowCustom":true,"banner":true},"colors":[{"id":"1","color":"#36b368","second":"#f0f9eb"},{"id":"2","color":"#2d8cf0","second":"#ecf5ff"},{"id":"3","color":"#f6ad55","second":"#fdf6ec"},{"id":"4","color":"#f56c6c","second":"#fef0f0"},{"id":"5","color":"#3963bc","second":"#ecf5ff"}],"other":{"keepLoad":"500","autoHead":true,"footer":false},"header":{"message":false}}', '2022-12-05 14:49:01', '2022-12-08 20:20:28'),
('table_form_schema_wa_users', '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"searchable":true,"search_type":"normal","form_show":false},"username":{"field":"username","_field_id":"1","comment":"用户名","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"nickname":{"field":"nickname","_field_id":"2","comment":"昵称","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"password":{"field":"password","_field_id":"3","comment":"密码","control":"input","control_args":"","form_show":true,"search_type":"normal","list_show":false,"enable_sort":false,"searchable":false},"sex":{"field":"sex","_field_id":"4","comment":"性别","control":"select","control_args":"url:/app/admin/dict/get/sex","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"avatar":{"field":"avatar","_field_id":"5","comment":"头像","control":"uploadImage","control_args":"url:/app/admin/upload/avatar","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"email":{"field":"email","_field_id":"6","comment":"邮箱","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"mobile":{"field":"mobile","_field_id":"7","comment":"手机","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"level":{"field":"level","_field_id":"8","comment":"等级","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"birthday":{"field":"birthday","_field_id":"9","comment":"生日","control":"datePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"money":{"field":"money","_field_id":"10","comment":"余额(元)","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"score":{"field":"score","_field_id":"11","comment":"积分","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"last_time":{"field":"last_time","_field_id":"12","comment":"登录时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"last_ip":{"field":"last_ip","_field_id":"13","comment":"登录ip","control":"input","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"join_time":{"field":"join_time","_field_id":"14","comment":"注册时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"join_ip":{"field":"join_ip","_field_id":"15","comment":"注册ip","control":"input","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"token":{"field":"token","_field_id":"16","comment":"token","control":"input","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"17","comment":"创建时间","control":"dateTimePicker","control_args":"","form_show":true,"search_type":"between","list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"18","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"between","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"role":{"field":"role","_field_id":"19","comment":"角色","control":"inputNumber","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"status":{"field":"status","_field_id":"20","comment":"禁用","control":"switch","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-23 15:28:13'),
('table_form_schema_wa_roles', '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"name":{"field":"name","_field_id":"1","comment":"角色组","control":"input","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"rules":{"field":"rules","_field_id":"2","comment":"权限","control":"treeSelectMulti","control_args":"url:/app/admin/rule/get?type=0,1,2","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"3","comment":"创建时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"4","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"pid":{"field":"pid","_field_id":"5","comment":"父级","control":"select","control_args":"url:/app/admin/role/select?format=tree","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-19 14:24:25'),
('table_form_schema_wa_rules', '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"title":{"field":"title","_field_id":"1","comment":"标题","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"icon":{"field":"icon","_field_id":"2","comment":"图标","control":"iconPicker","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"key":{"field":"key","_field_id":"3","comment":"标识","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"pid":{"field":"pid","_field_id":"4","comment":"上级菜单","control":"treeSelect","control_args":"/app/admin/rule/select?format=tree&type=0,1","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"5","comment":"创建时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"6","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"href":{"field":"href","_field_id":"7","comment":"url","control":"input","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"type":{"field":"type","_field_id":"8","comment":"类型","control":"select","control_args":"data:0:目录,1:菜单,2:权限","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"weight":{"field":"weight","_field_id":"9","comment":"排序","control":"inputNumber","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-08 11:44:45'),
('table_form_schema_wa_admins', '{"id":{"field":"id","_field_id":"0","comment":"ID","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"search_type":"between","form_show":false,"searchable":false},"username":{"field":"username","_field_id":"1","comment":"用户名","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"nickname":{"field":"nickname","_field_id":"2","comment":"昵称","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"password":{"field":"password","_field_id":"3","comment":"密码","control":"input","control_args":"","form_show":true,"search_type":"normal","list_show":false,"enable_sort":false,"searchable":false},"avatar":{"field":"avatar","_field_id":"4","comment":"头像","control":"uploadImage","control_args":"url:/app/admin/upload/avatar","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"email":{"field":"email","_field_id":"5","comment":"邮箱","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"mobile":{"field":"mobile","_field_id":"6","comment":"手机","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"created_at":{"field":"created_at","_field_id":"7","comment":"创建时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"updated_at":{"field":"updated_at","_field_id":"8","comment":"更新时间","control":"dateTimePicker","control_args":"","form_show":true,"search_type":"normal","list_show":false,"enable_sort":false,"searchable":false},"login_at":{"field":"login_at","_field_id":"9","comment":"登录时间","control":"dateTimePicker","control_args":"","form_show":true,"list_show":true,"search_type":"between","enable_sort":false,"searchable":false},"status":{"field":"status","_field_id":"10","comment":"禁用","control":"switch","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-23 15:36:48'),
('table_form_schema_wa_options', '{"id":{"field":"id","_field_id":"0","comment":"","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"name":{"field":"name","_field_id":"1","comment":"键","control":"input","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"value":{"field":"value","_field_id":"2","comment":"值","control":"textArea","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"3","comment":"创建时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"4","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-08 11:36:57'),
('table_form_schema_wa_uploads', '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"search_type":"normal","form_show":false,"searchable":false},"name":{"field":"name","_field_id":"1","comment":"名称","control":"input","control_args":"","list_show":true,"searchable":true,"search_type":"normal","form_show":false,"enable_sort":false},"url":{"field":"url","_field_id":"2","comment":"文件","control":"upload","control_args":"url:/app/admin/upload/file","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"admin_id":{"field":"admin_id","_field_id":"3","comment":"管理员","control":"select","control_args":"url:/app/admin/admin/select?format=select","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"file_size":{"field":"file_size","_field_id":"4","comment":"文件大小","control":"inputNumber","control_args":"","list_show":true,"search_type":"between","form_show":false,"enable_sort":false,"searchable":false},"mime_type":{"field":"mime_type","_field_id":"5","comment":"mime类型","control":"input","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"image_width":{"field":"image_width","_field_id":"6","comment":"图片宽度","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"image_height":{"field":"image_height","_field_id":"7","comment":"图片高度","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"ext":{"field":"ext","_field_id":"8","comment":"扩展名","control":"input","control_args":"","list_show":true,"searchable":true,"search_type":"normal","form_show":false,"enable_sort":false},"storage":{"field":"storage","_field_id":"9","comment":"存储位置","control":"input","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"10","comment":"上传时间","control":"dateTimePicker","control_args":"","searchable":true,"search_type":"between","form_show":false,"list_show":false,"enable_sort":false},"category":{"field":"category","_field_id":"11","comment":"类别","control":"select","control_args":"url:/app/admin/dict/get/upload","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"updated_at":{"field":"updated_at","_field_id":"12","comment":"更新时间","control":"dateTimePicker","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-08 11:47:45'),
('dict_upload', '[{"value":"1","name":"分类1"},{"value":"2","name":"分类2"},{"value":"3","name":"分类3"}]', '2022-12-04 16:24:13', '2022-12-04 16:24:13'),
('dict_sex', '[{"value":"0","name":"女"},{"value":"1","name":"男"}]', '2022-12-04 15:04:40', '2022-12-04 15:04:40'),
('dict_status', '[{"value":"0","name":"正常"},{"value":"1","name":"禁用"}]', '2022-12-04 15:05:09', '2022-12-04 15:05:09'),
('table_form_schema_wa_admin_roles', '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"searchable":true,"search_type":"normal","form_show":false},"role_id":{"field":"role_id","_field_id":"1","comment":"角色id","control":"inputNumber","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"admin_id":{"field":"admin_id","_field_id":"2","comment":"管理员id","control":"inputNumber","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}', '2022-08-15 00:00:00', '2022-12-20 19:42:51'),
('dict_dict_name', '[{"value":"dict_name","name":"字典名称"},{"value":"status","name":"启禁用状态"},{"value":"sex","name":"性别"},{"value":"upload","name":"附件分类"}]', '2022-08-15 00:00:00', '2022-12-20 19:42:51');

CREATE TABLE IF NOT EXISTS wa_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(80) NOT NULL,
    rules TEXT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    pid INTEGER DEFAULT NULL
);

INSERT INTO wa_roles (id, name, rules, created_at, updated_at, pid) VALUES (1, '超级管理员', '*', '2022-08-13 16:15:01', '2022-12-23 12:05:07', NULL);

CREATE TABLE IF NOT EXISTS wa_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(255) NOT NULL,
    icon VARCHAR(255) DEFAULT NULL,
    key VARCHAR(255) NOT NULL,
    pid INTEGER DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    href VARCHAR(255) DEFAULT NULL,
    type INTEGER NOT NULL DEFAULT 1,
    open_type VARCHAR(255) DEFAULT NULL,
    weight INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS wa_uploads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(128) NOT NULL,
    url VARCHAR(255) NOT NULL,
    admin_id INTEGER DEFAULT NULL,
    user_id INTEGER DEFAULT NULL,
    file_size INTEGER NOT NULL,
    mime_type VARCHAR(255) NOT NULL,
    image_width INTEGER DEFAULT NULL,
    image_height INTEGER DEFAULT NULL,
    ext VARCHAR(128) NOT NULL,
    storage VARCHAR(255) DEFAULT 'local',
    created_at DATE DEFAULT NULL,
    category VARCHAR(128) DEFAULT NULL,
    updated_at DATE DEFAULT NULL
);

CREATE INDEX idx_uploads_category ON wa_uploads(category);
CREATE INDEX idx_uploads_admin_id ON wa_uploads(admin_id);
CREATE INDEX idx_uploads_name ON wa_uploads(name);
CREATE INDEX idx_uploads_ext ON wa_uploads(ext);

CREATE TABLE IF NOT EXISTS wa_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(32) NOT NULL UNIQUE,
    nickname VARCHAR(40) NOT NULL,
    password VARCHAR(255) NOT NULL,
    sex VARCHAR(10) DEFAULT '1',
    avatar VARCHAR(255) DEFAULT NULL,
    email VARCHAR(128) DEFAULT NULL,
    mobile VARCHAR(16) DEFAULT NULL,
    level INTEGER NOT NULL DEFAULT 0,
    birthday DATE DEFAULT NULL,
    money NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    score INTEGER NOT NULL DEFAULT 0,
    last_time DATETIME DEFAULT NULL,
    last_ip VARCHAR(50) DEFAULT NULL,
    join_time DATETIME DEFAULT NULL,
    join_ip VARCHAR(50) DEFAULT NULL,
    token VARCHAR(50) DEFAULT NULL,
    created_at DATETIME DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,
    role INTEGER NOT NULL DEFAULT 1,
    status INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_users_join_time ON wa_users(join_time);
CREATE INDEX idx_users_mobile ON wa_users(mobile);
CREATE INDEX idx_users_email ON wa_users(email);