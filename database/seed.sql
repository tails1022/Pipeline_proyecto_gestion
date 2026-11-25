-- Datos iniciales idempotentes
INSERT INTO mesas (numero, capacidad, ubicacion) VALUES
    (1, 2, 'Ventana'),
    (2, 4, 'Salón Principal'),
    (3, 4, 'Salón Principal'),
    (4, 6, 'Terraza'),
    (5, 8, 'Salón VIP')
ON CONFLICT (numero) DO NOTHING;

INSERT INTO platos (nombre, descripcion, precio, categoria) VALUES
    ('Lomo Saltado Clásico', 'Trozos de res salteados con cebolla, tomate y papas fritas', 38.00, 'Platos Fuertes'),
    ('Salmón a la Plancha', 'Filete de salmón con vegetales al vapor y salsa de limón', 45.00, 'Platos Fuertes'),
    ('Risotto de Champiñones', 'Arroz arborio cremoso con hongos portobello y parmesano', 34.00, 'Platos Fuertes'),
    ('Limonada de Hierbabuena', 'Bebida refrescante natural 400ml', 9.50, 'Bebidas'),
    ('Tiramisú Artesanal', 'Postre italiano con café espresso y mascarpone', 16.00, 'Postres')
ON CONFLICT (nombre) DO NOTHING;
