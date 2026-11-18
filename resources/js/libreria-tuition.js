export function createTuitionState() {
    return {
        tuitionStudents: [],
        tuitionResponsibleTypes: [],
        tuitionStudent: null,
        tuitionStudentSearch: '',
        tuitionStudentPage: 1,
        tuitionStudentPages: 1,
        tuitionTotalStudents: 0,
        tuitionStudentsLoading: false,
        tuitionStudentLoading: false,
        tuitionFeesLoading: false,
        tuitionStudentsError: '',
        tuitionStudentError: '',
        tuitionFeesError: '',
        tuitionSearchSequence: 0,
        tuitionStudentSequence: 0,
        tuitionFeesSequence: 0,
        tuitionChoosingStudent: true,
        tuitionResponsibleType: '',
        tuitionFees: [],
        tuitionSelectedFeeIds: [],

        get tuitionStudentsInSale() {
            const students = new Map();
            for (const item of this.items) {
                if (!item.tuition) continue;
                const { studentId, studentName, studentCode } = item.tuition;
                if (!students.has(studentId)) {
                    students.set(studentId, { id: studentId, name: studentName, code: studentCode, feeCount: 0 });
                }
                students.get(studentId).feeCount++;
            }
            return [...students.values()];
        },

        get tuitionStudentsLabel() {
            const students = this.tuitionStudentsInSale;
            return students.length === 1 ? students[0].name : students.length ? `${students.length} alumnos` : '';
        },

        get tuitionResponsibleLabel() {
            return this.tuitionResponsibleTypes.find(type => type.id === this.tuitionResponsibleType)?.label || '';
        },

        get tuitionBilling() {
            return this.tuitionResponsibleTypes.find(type => type.id === this.tuitionResponsibleType)?.billing || null;
        },

        tuitionFeeKey(fee) {
            return `tuition:${this.tuitionStudent.id}:${fee.id}`;
        },

        tuitionFeeInSale(fee) {
            return this.tuitionStudent && this.items.some(item => item.id === this.tuitionFeeKey(fee));
        },

        get tuitionConfigurationErrors() {
            return [...new Set(this.tuitionFees.filter(fee => fee.remaining > 0 && !fee.canSelect)
                .map(fee => `${fee.name}: ${fee.configurationError || 'Falta configurar su producto.'}`))];
        },

        get selectableTuitionFees() {
            return this.tuitionFeesLoading || this.tuitionStudentLoading || this.tuitionFeesError
                ? [] : this.tuitionFees.filter(fee => fee.remaining > 0 && fee.canSelect === true && fee.product && !this.tuitionFeeInSale(fee));
        },

        get selectedTuitionFees() {
            return this.selectableTuitionFees.filter(fee => this.tuitionSelectedFeeIds.includes(fee.id));
        },

        get tuitionSelectedTotal() {
            return this.selectedTuitionFees.reduce((total, fee) => total + fee.remaining, 0);
        },

        get allTuitionFeesSelected() {
            return this.selectableTuitionFees.length > 0 && this.selectableTuitionFees.every(fee => this.tuitionSelectedFeeIds.includes(fee.id));
        },

        async tuitionRequest(url) {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('No se pudieron consultar los datos escolares. Intenta nuevamente.');
            return response.json();
        },

        async searchTuitionStudents(page = 1) {
            const sequence = ++this.tuitionSearchSequence;
            this.tuitionStudentsLoading = true;
            this.tuitionStudentsError = '';
            this.tuitionStudents = [];
            try {
                const query = new URLSearchParams({ q: this.tuitionStudentSearch.trim(), page });
                const data = await this.tuitionRequest(`${this.saleCatalogs.studentSearchUrl}?${query}`);
                if (sequence !== this.tuitionSearchSequence) return;
                this.tuitionStudents = data.data;
                this.tuitionStudentPage = data.current_page;
                this.tuitionStudentPages = data.last_page;
                this.tuitionTotalStudents = data.total;
            } catch {
                if (sequence === this.tuitionSearchSequence) {
                    this.tuitionTotalStudents = 0;
                    this.tuitionStudentsError = 'No se pudo cargar el catálogo de alumnos. Intenta nuevamente.';
                }
            } finally {
                if (sequence === this.tuitionSearchSequence) this.tuitionStudentsLoading = false;
            }
        },

        async openTuition() {
            this.tuitionStudentSearch = '';
            this.tuitionStudentPage = 1;
            this.tuitionSelectedFeeIds = [];
            this.tuitionChoosingStudent = !this.tuitionStudent;
            this.$flux.modal('sale-tuition').show();
            if (this.tuitionChoosingStudent) await this.searchTuitionStudents();
        },

        async chooseAnotherTuitionStudent() {
            this.tuitionChoosingStudent = true;
            this.tuitionStudentSearch = '';
            await this.searchTuitionStudents();
        },

        async selectTuitionStudent(student) {
            const sequence = ++this.tuitionStudentSequence;
            ++this.tuitionFeesSequence;
            this.tuitionFeesLoading = false;
            this.tuitionFeesError = '';
            this.tuitionStudent = student;
            this.tuitionChoosingStudent = false;
            this.tuitionResponsibleType = '';
            this.tuitionResponsibleTypes = [];
            this.tuitionFees = [];
            this.tuitionSelectedFeeIds = [];
            this.tuitionStudentLoading = true;
            this.tuitionStudentError = '';
            try {
                const data = await this.tuitionRequest(`${this.saleCatalogs.studentSearchUrl}/${encodeURIComponent(student.id)}`);
                if (sequence !== this.tuitionStudentSequence) return;
                this.tuitionStudent = data.student;
                this.tuitionResponsibleTypes = data.responsibles;
            } catch {
                if (sequence === this.tuitionStudentSequence) this.tuitionStudentError = 'No se pudieron cargar los responsables del alumno. Intenta nuevamente.';
            } finally {
                if (sequence === this.tuitionStudentSequence) this.tuitionStudentLoading = false;
            }
        },

        async loadTuitionFees() {
            const sequence = ++this.tuitionFeesSequence;
            this.tuitionSelectedFeeIds = [];
            this.tuitionFees = [];
            this.tuitionFeesError = '';
            this.tuitionFeesLoading = false;
            if (!this.tuitionStudent || !this.tuitionBilling || this.tuitionStudentLoading) return;
            this.tuitionFeesLoading = true;
            try {
                const query = new URLSearchParams({ responsable: this.tuitionResponsibleType });
                const data = await this.tuitionRequest(`${this.saleCatalogs.studentSearchUrl}/${encodeURIComponent(this.tuitionStudent.id)}/cuotas?${query}`);
                if (sequence !== this.tuitionFeesSequence) return;
                this.tuitionFees = data.fees;
                if (this.tuitionConfigurationErrors.length) {
                    this.$flux.modal('sale-tuition-configuration').show();
                }
            } catch {
                if (sequence === this.tuitionFeesSequence) this.tuitionFeesError = 'No se pudieron cargar las cuotas. Intenta nuevamente.';
            } finally {
                if (sequence === this.tuitionFeesSequence) this.tuitionFeesLoading = false;
            }
        },

        toggleAllTuitionFees() {
            this.tuitionSelectedFeeIds = this.allTuitionFeesSelected ? [] : this.selectableTuitionFees.map(fee => fee.id);
        },

        confirmTuitionFees(continueWithAnotherStudent = false) {
            const selected = this.selectedTuitionFees;
            if (this.tuitionChoosingStudent || !this.tuitionStudent || !this.tuitionBilling || !selected.length) {
                return;
            }

            if (!this.hasBillingClient || this.clientIsDefault) {
                const billingClient = this.clientFromTuition();
                if (!billingClient) return;
                this.applyBillingClient(billingClient);
            }

            for (const fee of selected) {
                this.addProduct({
                    id: this.tuitionFeeKey(fee),
                    productId: fee.product.id,
                    code: fee.product.code,
                    name: fee.name,
                    category: 'colegiaturas',
                    price: fee.remaining,
                    taxable: fee.product.taxType === 'A',
                    origin: fee.product.origin,
                    incomeAccount: fee.product.incomeAccount,
                    priceType: fee.product.priceType,
                    unit: fee.product.unit,
                    tuition: {
                        studentId: this.tuitionStudent.id,
                        studentName: this.tuitionStudent.name,
                        studentCode: this.tuitionStudent.code,
                        feeId: fee.id,
                        balanceId: fee.balanceId,
                        schoolYear: fee.schoolYear,
                        month: fee.month,
                        fundingType: fee.fundingType,
                        amount: fee.remaining,
                        barcode: fee.code,
                        responsibleType: this.tuitionResponsibleType,
                        responsibleLabel: this.tuitionResponsibleLabel,
                    },
                });
            }

            this.tuitionSelectedFeeIds = [];
            if (continueWithAnotherStudent) {
                this.resetTuition();
                void this.searchTuitionStudents();
                return;
            }
            this.$flux.modal('sale-tuition').close();
        },

        resetTuition() {
            ++this.tuitionSearchSequence;
            ++this.tuitionStudentSequence;
            ++this.tuitionFeesSequence;
            this.tuitionStudents = [];
            this.tuitionResponsibleTypes = [];
            this.tuitionStudentPages = 1;
            this.tuitionTotalStudents = 0;
            this.tuitionStudentsLoading = false;
            this.tuitionStudentLoading = false;
            this.tuitionFeesLoading = false;
            this.tuitionStudentsError = '';
            this.tuitionStudentError = '';
            this.tuitionFeesError = '';
            this.tuitionStudent = null;
            this.tuitionChoosingStudent = true;
            this.tuitionStudentSearch = '';
            this.tuitionStudentPage = 1;
            this.tuitionResponsibleType = '';
            this.tuitionFees = [];
            this.tuitionSelectedFeeIds = [];
        },
    };
}
