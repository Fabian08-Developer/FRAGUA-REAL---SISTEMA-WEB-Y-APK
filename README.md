# Base de datos — Fragua Real Burger

Migraciones de Laravel (PostgreSQL) y modelos Eloquent para los 9 módulos (M00-M08)
especificados en los documentos de requisitos.

## Cómo instalar

```bash
composer create-project laravel/laravel fragua-real
cd fragua-real
# Copia database/migrations/*.php y app/Models/*.php de este paquete a tu proyecto
# Configura .env con tu conexión PostgreSQL
php artisan migrate
```

## Orden de las migraciones

Los archivos ya están numerados en el orden correcto de dependencias (una tabla
nunca referencia a otra que se cree después):

1. `users` → 2. `categorias` → 3. `mesas` → 4. `productos` → 5. `variantes` →
6. `extras` → 7. `combos` → 8. `combo_producto` → 9. `promociones` →
10. `promocion_producto` → 11. `promocion_categoria` → 12. `reservas` →
13. `caja_turnos` → 14. `pedidos` → 15. `pedido_detalles` →
16. `pedido_detalle_extra` → 17. `pagos` → 18. `comprobantes` →
19. `compras_gastos` → 20. `cierres_diarios` → 21. `auditorias` →
22. `password_reset_tokens` (RF-M01-012/013, recuperación de contraseña)

Migraciones posteriores: `personal_access_tokens` (Sanctum, 25/09), `configuraciones` (28/09)
y `hacer_nullable_producto_id_en_pedido_detalles` (29/09; las líneas de combo no llevan
`producto_id`).

Fase 0 del plan de implementación (09/10/2026), todas aditivas: `2026_10_13_000001` entrega y
recogida en `pedidos`, `000002` tabla `ajustes_caja` y tipo de reverso en `pagos`, `000003`
`token_publico` en `pedidos` y `000004` IP y navegador en `auditorias`. Detalle en
`README-BACKEND.md`, punto 15.

Fase 1 (10/10/2026): `2026_10_14_000001` crea `pedido_modificaciones`, el historial de cambios a
las líneas de un pedido (RN-PED-12), y `2026_10_14_000002` agrega `telefono_contacto` y
`observaciones_entrega` a `pedidos`, `000003` el método y la pasarela de pago elegidos, y
`000004` `mesas.ocupada_desde` (inicio de la visita, para la cuenta de la mesa), y `000005`
`reservas.creado_por` con `administrador` y `reservas.registrada_por`, y `000006`
`variantes.disponible`; detalle en `README-BACKEND.md`, puntos 16 a 21.

Fase 2 (11/10/2026): `2026_10_15_000001` agrega a `pedidos` el descuento, la foto de las
promociones vigentes al crearlo, las promociones aplicadas y el descuento manual (regla, motivo,
quién y cuándo), y a `pedido_detalles` la parte del descuento de cada línea; detalle en
`README-BACKEND.md`, punto 22. `2026_10_15_000002` agrega el tipo de pago (`cobro` o `deposito`)
y su reserva en `pagos`, y en `reservas` el pedido al que se abonó el depósito, cuánto se abonó, la
hora de llegada y los turnos de caja en que se recibió y se devolvió el depósito; detalle en el
punto 23. `2026_10_16_000001` crea `producto_extra`, los extras generales que ofrece cada producto
(punto 24). `2026_10_16_000002` completa `compras_gastos`: cantidad, unidad, observaciones, el
soporte guardado en almacenamiento privado y quién corrigió o anuló cada registro (punto 25).
`2026_10_16_000003` guarda el desglose del cierre diario y su turno de caja, y crea
`cierre_ajustes`, las correcciones autorizadas después del cierre (punto 26).

Fase 3 (12/10/2026): `2026_10_17_000001` protege el historial de auditoría en la base de datos
(triggers que rechazan cambios y borrados en `auditorias`) y agrega un índice por fecha; detalle en
`README-BACKEND.md`, punto 27. La tarea 2 (editar usuario y cambiar su rol, punto 28) no necesita
migración. Pruebas automáticas: `php artisan test` (189 en `tests/Feature`).

## Decisiones de arquitectura (ADR)

### ADR-001 — Una sola tabla `pedidos` para Local, QR, Domicilio y Recogida

M02 y M07 comparten el mismo mecanismo de estados (PENDIENTE → CONFIRMADO →
PREPARANDO → FINALIZADO/CANCELADO) e impresión POS. En vez de duplicar esa
lógica en dos tablas, `pedidos.tipo` distingue el canal (`local`, `domicilio`,
`recogida`) y las columnas específicas de cada canal quedan en `NULL` cuando
no aplican:

- `mesa_id` → solo `local`
- `direccion_entrega`, `barrio`, `referencia`, `valor_domicilio` → solo `domicilio`
- `cliente_invitado_nombre/telefono` → domicilio o recogida sin cuenta

### ADR-002 — Precio congelado en cada línea de pedido

`pedido_detalles.precio_unitario_congelado` y
`pedido_detalle_extra.precio_congelado` copian el precio vigente al momento
del pedido. Si el Administrador cambia el precio de un producto en `productos`
mañana, los pedidos históricos no se alteran (recomendación de arquitectura
repetida en M02, M04 y M07).

### ADR-003 — Pagos como tabla 1:N, no una columna en `pedidos`

Un pedido puede pagarse con métodos combinados (RN-M06-04: pago mixto), así
que `pagos` es una tabla aparte en vez de un campo `metodo_pago` en `pedidos`.
La suma de los `pagos` no anulados de un pedido debe igualar `pedidos.total`.

### ADR-004 — `pedidos.finalizado_por` registra siempre al Mesero

Por la regla de negocio de M06 sobre quién cobra (RF-M06-003, RF-M06-004,
RNF-M06-004):

- **Local, domicilio contra entrega y recogida** → los cobra siempre el
  **Mesero**, sin importar el canal. El Administrador nunca registra pagos:
  solo abre/cierra caja (RN-M06-01) y autoriza anulaciones (RF-M06-017).
- **Pago en línea** (Nequi/Bancolombia) → el Cliente paga directamente;
  `pagos.registrado_por` queda en `NULL` en ese caso.

El método `Pedido::actorQueCobra()` en el modelo documenta esta regla, pero
la aplicación real de permisos debe hacerse en el controlador/policy
correspondiente, no solo confiar en este helper.

### ADR-005 — `extras.producto_id` nulo = extra general

En vez de una tabla pivote `producto_extra`, un extra con `alcance = general`
y `producto_id = NULL` está disponible para cualquier producto; un extra
`especifico` siempre tiene `producto_id` poblado (RN-M04-05).

### ADR-006 — M08 (Reportes) no tiene tablas propias

M08 es de solo lectura (RN-M08-01): sus 6 reportes se construyen con
consultas agregadas sobre `pedidos`, `pedido_detalles`, `pagos`,
`compras_gastos`, `cierres_diarios` y `reservas`. No se generó ninguna
migración para M08 a propósito.

## Pendientes que SÍ debes resolver en el código (no en la base de datos)

Estas reglas de negocio no se pueden expresar solo con columnas/constraints
de SQL — quedan como responsabilidad de los Controllers, Policies o
Form Requests de Laravel:

- Que solo el **Mesero** pueda registrar cobros presenciales, sin importar el
  canal (`local`, `domicilio` contra entrega o `recogida`); el **Administrador**
  nunca cobra — solo abre/cierra caja y autoriza anulaciones (RF-M06-003,
  RF-M06-004, RNF-M06-004).
- Que la suma de `pagos.monto` (no anulados) de un pedido nunca exceda su
  `total` (RF-M06-011).
- Que no se pueda abrir un `caja_turnos` si ya existe uno con `estado = abierta`
  (RN-M06-03) — se sugiere un índice único parcial en PostgreSQL:
  `CREATE UNIQUE INDEX ... ON caja_turnos (estado) WHERE estado = 'abierta';`
- Que un combo se oculte automáticamente si algún componente está agotado
  (RN-M04-11) — ya está como método `Combo::disponible()`, pero debe
  invalidarse/recalcularse cuando cambie la disponibilidad de un producto.
- El cálculo de `reservas.deposito_valor` y `pedidos.valor_domicilio` debe
  copiar el valor vigente configurado por el Administrador en el momento de
  crear el registro (no una relación en vivo a una tabla de configuración,
  que no se modeló aquí — se sugiere una tabla `configuraciones` simple
  clave/valor si prefieres no hardcodear esto en código).

## Tabla de configuraciones (implementada el 28/09/2026)

> Ya existe: migración `2026_09_28_000001_create_configuraciones_table`, modelo
> `Configuracion` y `ConfiguracionController` (`GET/PUT /configuraciones`, solo Administrador).
> El texto de abajo es el razonamiento original que la motivó.

Ni el valor del depósito de reserva (M05) ni el valor fijo de domicilio (M07)
tienen su propia tabla — ambos se guardan como el valor vigente copiado en
`reservas.deposito_valor` y `pedidos.valor_domicilio` respectivamente. Si
quieres que el Administrador pueda editarlos desde una pantalla de
configuración, te recomiendo agregar una migración `configuraciones` simple
(clave, valor, tipo) en vez de columnas sueltas — avísame si quieres que la
genere.
